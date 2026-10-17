 'use strict';
(() => {
 const messages = window.PULSE_MESSAGES;
 const locales = {'en':'en-US','pt-BR':'pt-BR','es':'es-ES','ja':'ja-JP','zh-CN':'zh-CN','ru':'ru-RU'};
 let language='en';
 try { const saved=localStorage.getItem('pulse-language'); if(locales[saved]) language=saved; } catch {}
 const records=[];
 function t(source) {
   if(messages[source]) return messages[source][language];
   for(const [key,translations] of Object.entries(messages)) {
     if(!key.includes('{code}')) continue;
     const parts=key.split('{code}');
     if(source.startsWith(parts[0]) && source.endsWith(parts[1])) {
       const code=source.slice(parts[0].length,source.length-parts[1].length);
       if(/^\d+$/.test(code)) return translations[language].replace('{code}',code);
     }
   }
   return source;
 }
 function refresh() {
   document.documentElement.lang=language;
   for(const r of records) {
     if(!r.node.isConnected) continue;
     if(r.attribute) r.node.setAttribute(r.attribute,t(r.source));
     else r.node.nodeValue=r.prefix+t(r.source)+r.suffix;
   }
 }
 function init() {
   const walker=document.createTreeWalker(document.documentElement,NodeFilter.SHOW_TEXT);
   while(walker.nextNode()) {
     const node=walker.currentNode;
     if(['SCRIPT','STYLE'].includes(node.parentElement?.tagName)) continue;
     const source=node.nodeValue.trim();
     if(messages[source]) records.push({node,source,prefix:node.nodeValue.match(/^\s*/)[0],suffix:node.nodeValue.match(/\s*$/)[0]});
   }
   for(const node of document.querySelectorAll('[placeholder],[aria-label],[title]')) {
     for(const attribute of ['placeholder','aria-label','title']) {
       const source=node.getAttribute(attribute);
       if(messages[source]) records.push({node,attribute,source});
     }
   }
   const select=document.querySelector('#language'); select.value=language;
   select.addEventListener('change',()=>{
     language=select.value; try{localStorage.setItem('pulse-language',language);}catch{}
     refresh(); window.dispatchEvent(new Event('pulse:languagechange'));
   });
   refresh();
 }
 window.PulseI18n={t,init,get language(){return language},get locale(){return locales[language]},number:value=>new Intl.NumberFormat(locales[language]).format(value),percent:value=>new Intl.NumberFormat(locales[language],{style:'percent',minimumFractionDigits:2,maximumFractionDigits:2}).format(value/100)};
 document.documentElement.lang=language;
})();
