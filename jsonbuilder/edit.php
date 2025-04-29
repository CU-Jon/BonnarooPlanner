<?php
/*  Bonnaroo JSON Editor – /jsonbuilder/edit.php  */
declare(strict_types=1);
session_start();

const NEW_SENTINEL = '__new__';
$jsonDir = realpath(__DIR__.'/../schedules') ?: __DIR__.'/../schedules';

/* ---------- Helpers ---------- */
function schedulePath(string $dir,string $type,int $year):string{
    return "$dir/$type"."_$year.json";
}
function loadSchedule(string $p):array{
    return (is_file($p)&&($d=json_decode(file_get_contents($p),true)))?$d:[];
}
function saveSchedule(string $p,array $d):void{
    file_put_contents($p,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
function minutesIdx(string $time):int{
    [$h,$m,$ap]=sscanf($time,"%d:%d %s");$h=($h%12)+($ap==='PM'?12:0);
    $min=$h*60+$m;return($min<=360)?$min+1440:$min; /* bump ≤6 AM */
}
function listFiles():array{
    global $jsonDir;$out=['centeroo'=>[],'outeroo'=>[]];
    foreach(glob("$jsonDir/*_[0-9][0-9][0-9][0-9].json") as$f)
        if(preg_match('~/(centeroo|outeroo)_(\d{4})\.json$~',$f,$m))$out[$m[1]][$m[2]]=$m[2];
    krsort($out['centeroo']);krsort($out['outeroo']);return$out;
}

/* ---------- AJAX: serve schedule ---------- */
if(isset($_GET['action'])&&$_GET['action']==='schedule'){
    header('Content-Type:application/json');
    echo json_encode(loadSchedule(schedulePath($jsonDir,strtolower($_GET['type']), (int)$_GET['year'])),JSON_UNESCAPED_SLASHES);exit;
}

/* ---------- POST: add / edit / delete ---------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
    $mode=$_POST['mode']??'add';
    $type=strtolower($_POST['type']??'');$year=(int)($_POST['year']??0);
    $file=schedulePath($jsonDir,$type,$year);$sched=loadSchedule($file);

    if($mode==='delete'){
        [$d,$loc,$idx]=[$_POST['d'],$_POST['loc'],(int)$_POST['idx']];
        if(isset($sched[$d][$loc][$idx])){array_splice($sched[$d][$loc],$idx,1);saveSchedule($file,$sched);}
        header("Location: edit.php?file={$type}_{$year}&flash=deleted");exit;
    }

    $day=$_POST['day']??'';
    $locSel=$_POST['stageSel']??'';$loc=($locSel===NEW_SENTINEL)?trim($_POST['locationNew']??''):$locSel;
    $artist=trim($_POST['artist']??'');
    $start=trim($_POST['start']??'');$startMer=$_POST['startMer']??'PM';
    $end  =trim($_POST['end']??'');  $endMer  =$_POST['endMer']??'PM';

    if(!$type||!$year||!$loc||!$artist||!$start||!$end||!$day){
        header("Location: edit.php?file={$type}_{$year}");exit;
    }

    if($mode==='edit'){
        [$oD,$oL,$oI]=[$_POST['oldDay'],$_POST['oldLoc'],(int)$_POST['oldIdx']];
        if(isset($sched[$oD][$oL][$oI])){
            $entry=['name'=>$artist,'start'=>"$start $startMer",'end'=>"$end $endMer"];
            if($oD===$day && $oL===$loc){$sched[$oD][$oL][$oI]=$entry;}
            else{array_splice($sched[$oD][$oL],$oI,1);$sched[$day][$loc][]=$entry;}
            saveSchedule($file,$sched);
        }
        header("Location: edit.php?file={$type}_{$year}&flash=edited");exit;
    }

    /* ----- ADD ----- */
    $dupe=false;
    if(isset($sched[$day][$loc])){
        foreach($sched[$day][$loc] as$e)
          if(!strcasecmp($e['name'],$artist)&&$e['start']==="$start $startMer"&&$e['end']==="$end $endMer"){$dupe=true;break;}
    }
    $flash=$dupe?'dupe':'added';
    if(!$dupe){$sched[$day][$loc][]= ['name'=>$artist,'start'=>"$start $startMer",'end'=>"$end $endMer"];saveSchedule($file,$sched);}
    header("Location: edit.php?file={$type}_{$year}&flash=$flash");exit;
}

/* ---------- initial render ---------- */
$files=listFiles();
$selected=$_GET['file']??'';
[$selType,$selYear]=explode('_',$selected)+['',''];

/* Flash message (if any) */
$msg='';
if(isset($_GET['flash'])){
   switch($_GET['flash']){
       case 'added':   $msg="<div class='ok'>Entry added.</div>";break;
       case 'edited':  $msg="<div class='ok'>Entry updated.</div>";break;
       case 'deleted': $msg="<div class='ok'>Entry deleted.</div>";break;
       case 'dupe':    $msg="<div class='warn'>Duplicate entry ignored.</div>";break;
   }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Bonnaroo JSON Editor</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;max-width:1000px;margin:20px auto}
fieldset{border:1px solid #aaa;padding:15px;margin-bottom:20px}legend{font-weight:bold}
label{display:inline-block;width:120px;margin-right:6px}
input[type=text],select{padding:5px;margin:4px 0}
button,input[type=submit]{padding:6px 14px;margin-left:4px}button:disabled{opacity:.5}
.ok{background:#e0ffe0;border:1px solid #7abf7a;padding:8px;margin-bottom:10px}
.warn{background:#ffe9e6;border:1px solid #d47a6e;padding:8px;margin-bottom:10px}
.day{margin-top:18px}.loc{margin-left:20px}
ul{margin:4px 0 10px 22px;padding:0}
</style>
<script>
const NEW_SENT='<?=NEW_SENTINEL?>';
const fmt=v=>{const d=v.replace(/\D/g,'');if(!d)return'';if(d.length<=2)return parseInt(d,10)+':00';
              if(d.length===3)return d[0]+':'+d.slice(1).padEnd(2,'0');if(d.length===4)return d.slice(0,2)+':'+d.slice(2);return v;};
function idx(t){const m=/(\d+):(\d+) (\w+)/.exec(t);let h=+m[1]%12;if(m[3]==='PM')h+=12;let min=h*60+ +m[2];return(min<=360)?min+1440:min;}

function fillLocs(data){
  stageSel.innerHTML='<option value="">— choose —</option><option value="'+NEW_SENT+'">(New Entry)</option>';
  const set=new Set;Object.values(data).forEach(st=>Object.keys(st).forEach(l=>set.add(l)));
  [...set].sort().forEach(l=>stageSel.add(new Option(l,l)));toggleNew();
}
function render(data){
  const pv=preview;pv.innerHTML='';
  for(const day in data){
    const dDiv=document.createElement('div');dDiv.className='day';dDiv.innerHTML=`<h3>${day}</h3>`;pv.append(dDiv);
    for(const loc in data[day]){
      const lDiv=document.createElement('div');lDiv.className='loc';lDiv.innerHTML=`<h4>${loc}</h4>`;dDiv.append(lDiv);
      const ul=document.createElement('ul');lDiv.append(ul);
      [...data[day][loc]].map((e,i)=>({...e,idx:i})).sort((a,b)=>idx(a.start)-idx(b.start))
       .forEach(e=>{
         const li=document.createElement('li');
         li.textContent=`${e.start} – ${e.end} — ${e.name} `;
         ['Edit','Delete'].forEach(act=>{
           const b=document.createElement('button');b.textContent=act;
           b.dataset.day=day;b.dataset.loc=loc;b.dataset.idx=e.idx;b.className=act.toLowerCase();
           li.append(b);
         });
         ul.append(li);
       });
    }
  }
}

/* ---------- load schedule ---------- */
function loadFile(){
  const sel=fileSelect.value;if(!sel){preview.innerHTML='';stageSel.innerHTML='';return;}
  const [t,y]=sel.split('_');typeH.value=t;yearH.value=y;
  fetch(`edit.php?action=schedule&type=${t}&year=${y}`).then(r=>r.json()).then(d=>{render(d);fillLocs(d);});
}

/* ---------- delegated buttons ---------- */
document.addEventListener('click',e=>{
  if(e.target.classList.contains('edit')){
     const {day,loc,idx}=e.target.dataset;const[t,y]=fileSelect.value.split('_');
     fetch(`edit.php?action=schedule&type=${t}&year=${y}`).then(r=>r.json()).then(d=>{
         const ev=d[day][loc][idx];
         mode.value='edit';oldDay.value=day;oldLoc.value=loc;oldIdx.value=idx;
         daySel.value=day;stageSel.value=loc;toggleNew();
         const[sH,sM,sAP]=ev.start.split(/[: ]/);start.value=sH+':'+sM;startMer.value=sAP;
         const[eH,eM,eAP]=ev.end.split(/[: ]/);end.value=eH+':'+eM;endMer.value=eAP;
         artist.value=ev.name;submitBtn.value='Save Edit';
         window.scrollTo({top:0,behavior:'smooth'});
     });
  }
  if(e.target.classList.contains('delete')){
     const {day,loc,idx}=e.target.dataset;if(!confirm('Delete this entry?'))return;
  }
  if(e.target.classList.contains('delete')){
     e.preventDefault();
  }
});
document.addEventListener('click',e=>{
  if(e.target.classList.contains('delete')){
    const {day,loc,idx}=e.target.dataset;
    const f=document.createElement('form');f.method='post';f.style.display='none';
    ['mode','type','year','d','loc','idx'].forEach(n=>{const i=document.createElement('input');i.name=n;f.append(i);});
    const[t,y]=fileSelect.value.split('_');f.mode.value='delete';f.type.value=t;f.year.value=y;
    f.d.value=day;f.loc.value=loc;f.idx.value=idx;document.body.append(f);f.submit();
  }
});
function toggleNew(){locationNew.disabled=(stageSel.value!==NEW_SENT);}
function cancelEdit(){mode.value='add';submitBtn.value='Add';artist.value='';start.value='';end.value='';}
document.addEventListener('DOMContentLoaded',()=>{
  fileSelect.addEventListener('change',loadFile);stageSel.addEventListener('change',toggleNew);
  ['start','end'].forEach(id=>document.getElementById(id).addEventListener('blur',e=>e.target.value=fmt(e.target.value)));
  <?php if($selected):?>fileSelect.value='<?=$selected?>';loadFile();<?php endif;?>
});
</script>
</head><body>

<h1>Bonnaroo JSON Editor</h1>
<p>Need to create a new schedule? Use the <a href="index.php">JSON Builder</a> instead.</p>
<?=$msg?>

<form method="post">
<input type="hidden" name="mode" id="mode" value="add">
<input type="hidden" name="type" id="typeH" value="<?=$selType?>">
<input type="hidden" name="year" id="yearH" value="<?=$selYear?>">
<input type="hidden" name="oldDay" id="oldDay">
<input type="hidden" name="oldLoc" id="oldLoc">
<input type="hidden" name="oldIdx" id="oldIdx">

<fieldset><legend>Choose Schedule File</legend>
<select id="fileSelect"><option value="">— select schedule —</option>
  <?php foreach($files as $grp=>$yrs){
        echo '<optgroup label="'.ucfirst($grp).'">';
        foreach($yrs as $y)echo"<option value='{$grp}_{$y}'".($selected==="{$grp}_{$y}"?' selected':'').">$y</option>";
        echo '</optgroup>';
  } ?>
</select>
</fieldset>

<fieldset><legend>Add / Edit Entry</legend>

<label>Artist/Event:</label>
 <input type="text" id="artist" name="artist"><br>

<label>Start:</label>
 <input type="text" id="start" name="start" size="6" placeholder="3:45">
 <select id="startMer" name="startMer"><option>AM</option><option selected>PM</option></select><br>

<label>End:</label>
 <input type="text" id="end" name="end" size="6" placeholder="5:00">
 <select id="endMer" name="endMer"><option>AM</option><option selected>PM</option></select><br>

<label>Day:</label>
 <select id="daySel" name="day"><?php foreach(['Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as$d)echo"<option>$d</option>";?></select><br>

<label>Location:</label>
 <select id="stageSel" name="stageSel"><option>— choose —</option><option value="<?=NEW_SENTINEL?>">(New Entry)</option></select><br>
<label>New location:</label>
 <input type="text" id="locationNew" name="locationNew" placeholder="enter new stage" disabled>
 <small>(required if “New Entry”)</small><br><br>

<input type="submit" id="submitBtn" value="Add">
<button type="button" onclick="cancelEdit()">Cancel Edit</button>
</fieldset>
</form>

<div id="preview"></div>
</body></html>
