'use strict';
/** Browser-less focused regression for seller/artist AJAX expiry redirect. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const page = fs.readFileSync('catalogus-public/admin/verkoper.php','utf8');
const start = page.indexOf('(function () {\n  document.querySelectorAll(\'[data-return-to-lot]\')');
assert.ok(start >= 0, 'seller editor inline script exists');
const end = page.indexOf('}());',start);
assert.ok(end > start,'seller editor inline script ends');
const source = page.slice(start,end+5);
async function runCase(responseURL, expectedRedirect) {
  let onSubmit, onLoad, xhr;
  const form = {
    reportValidity:()=>true,
    getAttribute:()=>'/admin/verkoper.php?id=7',
    querySelector:()=>({}),
    addEventListener(type, cb){if(type==='submit')onSubmit=cb;}
  };
  const button={disabled:false,dataset:{},textContent:'Wijzigingen opslaan'};
  const panel={hidden:true}, bar={removeAttribute(){}};
  const pct={hidden:true}, status={textContent:''}, error={hidden:true,textContent:''};
  const els={
    'seller-manager-form':form,'seller-save-progress':panel,
    'seller-upload-progress':bar,'seller-save-percent':pct,
    'seller-save-status':status,'seller-save-error':error,
    'seller-type':null
  };
  const assigned=[];
  const window={
    XMLHttpRequest:function(){},FormData:function(){},
    location:{href:'http://localhost:8082/admin/verkoper.php?id=7',origin:'http://localhost:8082',assign(url){assigned.push(url);}},
    addEventListener(){}
  };
  function XMLHttpRequest() {
    xhr=this;
    this.responseURL=responseURL;
    this.status=200;
    this.response=null;
    this.upload={addEventListener(){}};
    this.open=(method,url)=>{assert.equal(method,'POST');assert.equal(url,'/admin/verkoper.php?id=7');};
    this.setRequestHeader=()=>{};
    this.addEventListener=(type,handler)=>{if(type==='load')onLoad=handler;};
    this.send=()=>{};
  }
  window.XMLHttpRequest=XMLHttpRequest;
  const document={
    querySelectorAll:()=>[button],
    getElementById:id=>els[id]??null
  };
  const sandbox={
    window,document,XMLHttpRequest,FormData:function(){return {}},
    URL, console
  };
  vm.runInNewContext(source,sandbox);
  assert.ok(onSubmit,'submit handler registered');
  onSubmit({preventDefault(){}});
  assert.ok(xhr,'XHR sent');
  onLoad();
  if(expectedRedirect) {
    assert.equal(assigned.length,1,'login redirect reached page');
    assert.equal(assigned[0],responseURL);
    assert.equal(error.hidden,true,'no generic failure shown');
  } else {
    assert.equal(assigned.length,0,'reject arbitrary response URL');
    assert.equal(error.hidden,false,'unknown response must stay an error');
  }
}
(async()=>{
  await runCase('http://localhost:8082/admin/login.php',true);
  await runCase('http://localhost:8082/admin/login.php?expired=1',true);
  await runCase('https://elsewhere.example/admin/login.php',false);
  await runCase('http://localhost:8082/admin/verkoper.php?id=7',false);
  console.log('seller_session_expiry_ajax_test: OK (4 scenarios)');
})().catch(e=>{console.error(e);process.exitCode=1;});
