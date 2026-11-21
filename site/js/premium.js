(function(){'use strict';function ready(){if(typeof Button==='undefined'||typeof html2canvas==='undefined'){return!1}
var intro=document.getElementById('intro');return!intro||intro.classList.contains('is-done')}
function upgrade(){var host=document.querySelector('.hero-cta');if(!host||!host.firstElementChild){return}
var link=host.firstElementChild;if(link.tagName!=='A'){return}
var button=new Button({text:(link.textContent||'Explore').trim(),size:'15',type:'pill',tintOpacity:0.25,onClick:function(){window.location.href=link.getAttribute('href')||'/products'}});button.element.classList.add('premium-cta');host.replaceChild(button.element,link)}
var tries=0;var timer=window.setInterval(function(){if(ready()){window.clearInterval(timer);try{upgrade()}catch(e){}}else if(++tries>60){window.clearInterval(timer)}},200)})()