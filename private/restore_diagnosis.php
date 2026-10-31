<?php
declare(strict_types=1);

/**
 * Read-only incident diagnosis, issue #2 phase 3B.
 * Never delete/move files, clear maintenance or modify database records.
 * Journal phases are NOT proof of DB commit; compare full MySQL and image sets.
 */
require_once __DIR__ . '/snapshots.php';

function recovery_report(string $status, array $fields=[]): array {
    return array_merge([
        'read_only'=>true,
        'status'=>$status,
        'requires_human_review'=>true,
        'will_modify_data'=>false,
        'maintenance_must_remain'=>$status!=='clear',
    ],$fields);
}

/** Deterministic full-table digest: no private row contents in the report. */
function recovery_table_digest(array $rows): string {
    $normalized=[];
    foreach($rows as $row){
        if(!is_array($row)||!array_key_exists('id',$row))
            throw new RuntimeException('Unexpected database row.');
        $key=(string)$row['id'];
        if(!preg_match('/^[0-9]+$/D',$key)||isset($normalized[$key]))
            throw new RuntimeException('Duplicate/invalid row identity.');
        ksort($row,SORT_STRING);
        foreach($row as $column=>$value){
            if(!is_string($column)||
              ($value!==null&&!is_string($value)&&!is_int($value)
                &&!is_float($value)&&!is_bool($value)))
                throw new RuntimeException('Unexpected column value type.');
            $row[$column]=$value===null?null:(string)$value;
        }
        $normalized[$key]=$row;
    }
    ksort($normalized,SORT_NATURAL);
    return hash('sha256',json_encode($normalized,
        JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
}

/** All snapshot tables must match within one consistent read transaction. */
function recovery_compare_database(PDO $pdo,array $target): array {
    if($pdo->inTransaction())throw new RuntimeException('Diagnostic requires a fresh transaction.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $differences=[];
        foreach(SNAP_TABLES as $table){
            $query='SELECT * FROM '.chr(96).$table.chr(96);
            $actual=$pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
            // Pre-name snapshots omit admins.name. Compare them as blank names;
            // a populated name in the live DB must still produce a mismatch.
            $expected=$target['tables'][$table];
            if($table==='admins') {
                foreach($expected as &$row) {
                    if(!array_key_exists('name', $row)) $row['name']='';
                }
                unset($row);
            }
            if(!hash_equals(recovery_table_digest($expected),
                            recovery_table_digest($actual))){
                $differences[]=$table;
            }
        }
        $analytics=visitor_geo_snapshot_export($pdo);
        if(!hash_equals(visitor_geo_snapshot_digest($target['analytics']??null),visitor_geo_snapshot_digest($analytics)))$differences[]='analytics';
        if(!$pdo->commit())throw new RuntimeException('Read transaction commit failed.');
        return ['matches_target'=>!$differences,'different_tables'=>$differences];
    }catch(Throwable $error){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $error;
    }
}

/** Hash the bytes of every photo IN the target ZIP, not merely its manifest. */
function recovery_verify_archive_photos(string $name,array $hashes): int {
    $zip=new ZipArchive();
    if($zip->open(snap_path($name))!==true)throw new RuntimeException('Archive unreadable.');
    $n=0;
    try {
        foreach($hashes as $entry=>$sha){
            if($entry==='database.json')continue;
            $stream=$zip->getStream($entry);
            if(!is_resource($stream))throw new RuntimeException('Missing photo stream.');
            try {
                $ctx=hash_init('sha256');
                while(!feof($stream)){
                    $chunk=fread($stream,1024*1024);
                    if($chunk===false||($chunk===''&&!feof($stream)))
                        throw new RuntimeException('Archive photo stream error.');
                    hash_update($ctx,$chunk);
                }
                if(!hash_equals($sha,hash_final($ctx)))
                    throw new RuntimeException('Archive photo bytes do not match manifest.');
            }finally{fclose($stream);}
            $n++;
        }
    }finally{$zip->close();}
    return $n;
}

/** Reject unexpected image paths, symlinks, missing or hash-mismatched files. */
function recovery_compare_photos(string $root,array $manifest): array {
    if(!is_dir($root)||is_link($root))
        return ['matches_target'=>false,'reason'=>'active_photos_missing_or_unsafe'];
    $actual=[];$unexpected=0;
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as $file){
        if($file->isLink())
            return ['matches_target'=>false,'reason'=>'symlink_in_active_photos'];
        if($file->isDir())continue;
        if(!$file->isFile())
            return ['matches_target'=>false,'reason'=>'non_file_in_active_photos'];
        $relative=str_replace(chr(92),'/',substr(
            $file->getPathname(),strlen($root)+1));
        if($relative==='.htaccess')continue;
        if(!preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.(?:jpg|png|webp)$~D',$relative)){
            $unexpected++;
        }else{
            $actual['uploads/'.$relative]=$file->getPathname();
        }
        if(count($actual)+$unexpected>20000)
            return ['matches_target'=>false,'reason'=>'too_many_active_files'];
    }
    $expected=$manifest;unset($expected['database.json']);
    if($unexpected!==0||count($actual)!==count($expected)
       ||array_diff_key($expected,$actual)||array_diff_key($actual,$expected)){
        return ['matches_target'=>false,'reason'=>'photo_set_mismatch',
            'expected_count'=>count($expected),'actual_count'=>count($actual),
            'unexpected_count'=>$unexpected];
    }
    foreach($expected as $entry=>$sha){
        $found=hash_file('sha256',$actual[$entry]);
        if($found===false||!hash_equals($sha,$found)){
            return ['matches_target'=>false,'reason'=>'photo_content_mismatch',
                'expected_count'=>count($expected),'actual_count'=>count($actual)];
        }
    }
    return ['matches_target'=>true,'expected_count'=>count($expected),
        'actual_count'=>count($actual)];
}

/**
 * Conservative state classifier for authorized offline clone / PHP CLI.
 * Holds MySQL advisory mutex; never performs a durable data mutation.
 */
function restore_recovery_diagnose(PDO $pdo): array {
    if(!restore_journal_pending()){
        return recovery_report('clear',[
            'maintenance_must_remain'=>false,
            'note'=>'No restore marker found. This is NOT a data-integrity audit.',
        ]);
    }
    try{catalog_data_lock($pdo,0);}
    catch(Throwable $error){
        return recovery_report('blocked_unverified',['reason'=>'catalog_lock_busy']);
    }
    try {
        $parent=dirname(snap_uploads());
        $orphan=glob($parent.'/.uploads-before-restore-*')?:[];
        $temps=glob(restore_journal_dir().'/.restore-active.tmp-*')?:[];
        if($temps)return recovery_report('blocked_unverified',['reason'=>'unfinished_journal_write']);
        try{$journal=restore_journal_read();}
        catch(Throwable $error){
            return recovery_report('blocked_unverified',['reason'=>'missing_or_invalid_journal']);
        }
        if(count($orphan)>1||
           ($orphan&&basename($orphan[0])!==$journal['old_uploads'])){
            return recovery_report('blocked_unverified',['reason'=>'unexpected_old_upload_directory']);
        }
        $oldPath=$parent.'/'.$journal['old_uploads'];
        $stagePath=$parent.'/'.$journal['stage_uploads'];
        if(is_link($oldPath)||is_link($stagePath)||is_link(snap_uploads())){
            return recovery_report('blocked_unverified',['reason'=>'unsafe_restore_symlink']);
        }
        if(is_dir($oldPath)!==($orphan!==[])){
            return recovery_report('blocked_unverified',['reason'=>'unexpected_old_upload_directory']);
        }
        $name=$journal['snapshot'];
        $zipPath=snap_path($name);
        if(!is_file($zipPath)||is_link($zipPath))
            return recovery_report('blocked_unverified',['reason'=>'target_archive_missing_or_unsafe']);
        $digest=hash_file('sha256',$zipPath);
        if($digest===false||!hash_equals($journal['snapshot_sha256'],$digest))
            return recovery_report('blocked_unverified',['reason'=>'target_archive_sha256_mismatch']);
        try {
            [$target,$hashes]=snap_verified($name);
            $zipPhotos=recovery_verify_archive_photos($name,$hashes);
            $db=recovery_compare_database($pdo,$target);
            $photos=recovery_compare_photos(snap_uploads(),$hashes);
        }catch(Throwable $error){
            return recovery_report('blocked_unverified',[
                'reason'=>'database_or_archive_verification_failed',
            ]);
        }
        // Check the archived ZIP was not changed while it was being read.
        $digestAfter=hash_file('sha256',$zipPath);
        if($digestAfter===false||!hash_equals($journal['snapshot_sha256'],$digestAfter))
            return recovery_report('blocked_unverified',[
                'reason'=>'target_archive_changed_during_diagnosis']);
        $status=$db['matches_target']&&$photos['matches_target']
            ?'target_verified_pending_review':'mixed_or_unknown_keep_maintenance';
        return recovery_report($status,[
            'journal_phase'=>$journal['phase'],
            'snapshot'=>$name,
            'target_archive_sha256_verified'=>true,
            'archive_photo_hashes_verified'=>true,
            'archive_photo_count'=>$zipPhotos,
            'database_matches_target'=>$db['matches_target'],
            'database_different_tables'=>$db['different_tables'],
            'active_photos_match_target'=>$photos['matches_target'],
            'active_photo_status'=>$photos['reason']??'exact_manifest_match',
            'old_photo_directory_preserved'=>is_dir($oldPath),
            'restore_stage_directory_preserved'=>is_dir($stagePath),
            'next_step'=>'KEEP_MAINTENANCE_AND_OBTAIN_HUMAN_REVIEW',
        ]);
    }finally{
        catalog_data_unlock($pdo);
    }
}
