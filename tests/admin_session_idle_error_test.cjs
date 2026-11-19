'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const src = fs.readFileSync('catalogus-public/admin/session-idle.js','utf8');
async function scenario(name, response) {
  let onClick, reqCount=0;
  const text = {textContent:''};
  const renew = {hidden:false,disabled:false,addEventListener(type,fn) {if(type==='click') onClick=fn;}};
  const login = {hidden:true};
  const banner = {hidden:true,dataset:{expires:'1800000500',renewUrl:'/admin/sessie-verlengen.php',csrf:'abc'},querySelector(){return text;}};
  const elements = {'admin-session-warning':banner,'admin-session-renew':renew,'admin-session-login':login};
  const document = {getElementById(id){return elements[id]},addEventListener(){},visibilityState:'visible'};
  const window = {setTimeout() {return 1;},clearTimeout() {}};
  const fetch = async (url,opts) => {reqCount++;assert.equal(opts.method,'POST');return await response();};
  vm.runInNewContext(src,{window,document,fetch,URLSearchParams,Date:{now:()=>1800000000000}});
  assert.equal(banner.hidden,false,`${name}: warning first`);
  await onClick();
  assert.equal(reqCount,1,`${name}: only manual request`);
  assert.equal(banner.hidden,false,`${name}: failure banner`);
  assert.equal(login.hidden,false,`${name}: login link`);
  assert.equal(renew.disabled,false,`${name}: button unlocked`);
  assert.match(text.textContent,/Kopieer niet-opgeslagen wijzigingen/,`${name}: data loss warning`);
}
(async()=>{
  await scenario('HTML redirect fallback',async()=>({ok:true,headers:{get(){return 'text/html'}},json(){throw Error('must not parse')}}));
  await scenario('403 expired/CSRF',async()=>({ok:false,headers:{get(){return 'application/json'}},json(){throw Error('must not parse')}}));
  await scenario('network failure',async()=>{throw Error('network')});
  await scenario('malformed JSON response',async()=>({ok:true,headers:{get(){return 'application/json'}},async json(){return {ok:true,expires_at:'bad'}}}));
  console.log('admin_session_idle_error_test: OK (4 scenarios)');
})().catch(e=>{console.error(e);process.exitCode=1});
