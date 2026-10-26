#!/usr/bin/env python3
"""Read-only bounded inspection of production upload directory modes."""
import json
import posixpath
import stat
from deploy_issue139_code import connect, remote_read

client, base = connect()
try:
    with client.open_sftp() as sftp:
        sentinel=remote_read(sftp,posixpath.join(base,"catalogus-public/admin/index.php"))
        if b"dashboard-table" not in sentinel: raise RuntimeError("Invalid production root")
        root=posixpath.join(base,"catalogus-public/uploads")
        records=[]
        def inspect(path, depth):
            if len(records)>=200: return
            info=sftp.lstat(path)
            kind="directory" if stat.S_ISDIR(info.st_mode) else "file" if stat.S_ISREG(info.st_mode) else "other"
            mode=stat.S_IMODE(info.st_mode)
            rel=path[len(root):].lstrip("/") or "."
            records.append({"path":rel,"kind":kind,"mode":oct(mode),"size":info.st_size if kind=="file" else None})
            if kind!="directory" or depth>=3: return
            for entry in sftp.listdir_attr(path)[:100]:
                if len(records)>=200: break
                if entry.filename in (".",".."): continue
                inspect(posixpath.join(path,entry.filename),depth+1)
        inspect(root,0)
        report={"root":root,"entries":records,"count":len(records)}
        with open("photo-permissions-readonly.json","w") as f: json.dump(report,f,indent=2)
        for x in records:
            if x["kind"]=="directory":
                print("DIR",x["path"],x["mode"])
        print("Files sampled:",sum(x["kind"]=="file" for x in records))
finally:
    client.close()
