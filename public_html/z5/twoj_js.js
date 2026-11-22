// Wspólne menu pobierane z header.php, zgodnie z szablonem.
function myLoadHeader(){const box=document.getElementById("myHeader");if(box)fetch("header.php").then(r=>r.ok?r.text():Promise.reject()).then(html=>{box.innerHTML=html;}).catch(()=>{});}
