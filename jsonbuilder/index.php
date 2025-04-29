<?php
/*  Bonnaroo Schedule JSON Builder – /jsonbuilder/index.php  */
declare(strict_types=1);
session_start();

const NEW_SENTINEL = '__new__';
$jsonDir = realpath(__DIR__.'/../schedules') ?: __DIR__.'/../schedules';

/* ---------- AJAX: locations or schedule ---------- */
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $type = strtolower($_GET['type'] ?? '');
    $year = (int)($_GET['year'] ?? 0);

    if ($_GET['action'] === 'locations') {
        echo json_encode(getLocations($type,$year), JSON_UNESCAPED_SLASHES);
    } elseif ($_GET['action'] === 'schedule') {
        $file = schedulePath($jsonDir,$type,$year);
        echo json_encode(loadSchedule($file), JSON_UNESCAPED_SLASHES);
    }
    exit;
}

/* ---------- helpers ---------- */
function schedulePath(string $dir,string $type,int $year):string{
    return "$dir/$type"."_$year.json";
}
function loadSchedule(string $p):array{
    return (is_file($p)&&($d=json_decode(file_get_contents($p),true)))?$d:[];
}
function saveSchedule(string $p,array $d):void{
    file_put_contents($p,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
function getLocations(string $type,int $year):array{
    global $jsonDir; if(!$type||!$year)return[];
    $loc=[];                            // N-1 … N-3
    for($i=1;$i<=3;$i++){
        foreach(loadSchedule(schedulePath($jsonDir,$type,$year-$i)) as $d=>$st) $loc=array_merge($loc,array_keys($st));
    }
    foreach(loadSchedule(schedulePath($jsonDir,$type,$year)) as $d=>$st) $loc=array_merge($loc,array_keys($st));
    if(isset($_SESSION['customLoc'][$type][$year])) $loc=array_merge($loc,array_keys($_SESSION['customLoc'][$type][$year]));
    $loc=array_unique($loc,SORT_STRING); sort($loc,SORT_NATURAL); return $loc;
}
function minIndex(string $timeHHMM_AP):int{           // late-night bump
    [$h,$m,$ap]=sscanf($timeHHMM_AP,"%d:%d %s");
    $h=($h%12)+($ap==='PM'?12:0); $min=$h*60+$m;
    return ($min<=360)?$min+1440:$min;                // ≤6 AM → next day +24 h
}

/* ---------- POST: add entry ---------- */
$msg=''; $justAdded=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $type=strtolower($_POST['type']??'');
    $year=(int)($_POST['year']??0);
    $selLoc=$_POST['stageSel']??''; $newLoc=trim($_POST['locationNew']??'');
    $loc=($selLoc===NEW_SENTINEL?$newLoc:$selLoc);

    $artist=trim($_POST['artist']??'');
    $start=trim($_POST['start']??''); $startMer=$_POST['startMer']??'PM';
    $end  =trim($_POST['end']??'');   $endMer  =$_POST['endMer']??'PM';
    $day  =$_POST['day']??'';

    if($loc){$_SESSION['lastDay']=$day; $_SESSION['lastLocation']=$loc;}
    if($selLoc===NEW_SENTINEL && $newLoc) $_SESSION['customLoc'][$type][$year][$newLoc]=true;

    if($type&&$year&&$loc&&$artist&&$start&&$end&&$day){
        $file=schedulePath($jsonDir,$type,$year);
        $sched=loadSchedule($file); $dupe=false;
        if(isset($sched[$day][$loc])){
            foreach($sched[$day][$loc] as$e)
              if(!strcasecmp($e['name'],$artist)&&$e['start']==="$start $startMer"&&$e['end']==="$end $endMer"){$dupe=true;break;}
        }
        if($dupe)$msg="<div class='warn'>Duplicate entry ignored.</div>";
        else{
            $sched[$day][$loc][]= ['name'=>$artist,'start'=>"$start $startMer",'end'=>"$end $endMer"];
            saveSchedule($file,$sched);
            $msg="<div class='ok'>Added <strong>$artist</strong> to <em>$loc</em>.</div>";
            $justAdded=true;
        }
    }
}

/* ---------- vars ---------- */
$type=$_POST['type']??''; $year=$_POST['year']??'';
$daySel=$_SESSION['lastDay']??''; $locSel=$_SESSION['lastLocation']??'';
$locOpts=($type&&$year)?getLocations($type,(int)$year):[];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Bonnaroo JSON Builder</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;max-width:900px;margin:20px auto}
fieldset{border:1px solid #aaa;padding:15px;margin-bottom:20px}legend{font-weight:bold}
label{display:inline-block;width:120px;margin-right:6px}
input[type=text],select{padding:5px;margin:4px 0}
button{padding:6px 14px}button:disabled{opacity:.5}
.ok{background:#e0ffe0;border:1px solid #7abf7a;padding:8px;margin-bottom:10px}
.warn{background:#ffe9e6;border:1px solid #d47a6e;padding:8px;margin-bottom:10px}
.day-block{margin-top:18px} .loc-block{margin-left:20px}
ul{margin:4px 0 10px 22px;padding:0}
</style>
<script>
const NEW_SENT='<?=NEW_SENTINEL?>';
const fmt = v => {const d=v.replace(/\D/g,''); if(!d)return''; if(d.length<=2)return parseInt(d,10)+':00';
                  if(d.length===3)return d[0]+':'+d.slice(1).padEnd(2,'0');
                  if(d.length===4)return d.slice(0,2)+':'+d.slice(2); return v;};
function toggleNew(){locationNew.disabled=(stageSel.value!==NEW_SENT); if(locationNew.disabled)locationNew.value=''; validate();}
function validate(){
  submitBtn.disabled = !(
    document.querySelector('input[name="type"]:checked') && /^\d{4}$/.test(year.value) &&
    stageSel.value && artist.value.trim() && start.value.trim() && end.value.trim() && day.value &&
    (!(stageSel.value===NEW_SENT) || locationNew.value.trim())
  );
}
/* ---------- build preview html from JSON ---------- */
function minsIndex(t){const m=/(\d+):(\d+) (\w+)/.exec(t);if(!m)return 9999;let h=+m[1]%12;if(m[3]==='PM')h+=12;let min=h*60+ +m[2];return(min<=360)?min+1440:min;}
function renderPreview(data){
  const div=document.getElementById('preview'); div.innerHTML='';
  const days=Object.keys(data);
  days.forEach(day=>{
    const dayDiv=document.createElement('div');dayDiv.className='day-block';
    dayDiv.innerHTML=`<h3>${day}</h3>`; div.appendChild(dayDiv);
    Object.keys(data[day]).forEach(loc=>{
      const locDiv=document.createElement('div');locDiv.className='loc-block';
      locDiv.innerHTML=`<h4>${loc}</h4>`; const ul=document.createElement('ul');
      const events=[...data[day][loc]].sort((a,b)=>minsIndex(a.start)-minsIndex(b.start));
      events.forEach(e=>{const li=document.createElement('li');li.textContent=`${e.start} – ${e.end} — ${e.name}`;ul.appendChild(li);});
      locDiv.appendChild(ul); dayDiv.appendChild(locDiv);
    });
  });
}
/* ---------- fetch preview whenever Type+Year set ---------- */
function loadPreview(){
  const t=document.querySelector('input[name="type"]:checked')?.value||'', y=year.value;
  if(!t||!/^\d{4}$/.test(y)){document.getElementById('preview').innerHTML=''; return;}
  fetch(`index.php?action=schedule&type=${t}&year=${y}`).then(r=>r.json()).then(renderPreview);
}
/* ---------- reload stage list ---------- */
function reloadStages(){
  stageSel.innerHTML='<option value="">— choose location —</option><option value="'+NEW_SENT+'">(New Entry)</option>';
  const t=document.querySelector('input[name="type"]:checked')?.value||'', y=year.value;
  if(!t||!/^\d{4}$/.test(y)){toggleNew();loadPreview();return;}
  fetch(`index.php?action=locations&type=${t}&year=${y}`).then(r=>r.json()).then(a=>{
    a.forEach(l=>{const o=new Option(l,l,l=="<?=addslashes($locSel)?>",l=="<?=addslashes($locSel)?>");stageSel.add(o);});
    if(!stageSel.value)stageSel.value="<?=addslashes($locSel)?>";
    toggleNew(); loadPreview();
  });
}
/* ---------- submit on Enter ---------- */
function keyEnter(e){if(e.key==='Enter'&&!submitBtn.disabled&&!e.target.matches('select,button')){e.preventDefault();submitBtn.click();}}
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('input,select').forEach(el=>el.addEventListener('input',validate));
  document.querySelectorAll('input[name="type"]').forEach(r=>r.addEventListener('change',reloadStages));
  year.addEventListener('input',reloadStages); stageSel.addEventListener('change',toggleNew);
  ['start','end'].forEach(id=>document.getElementById(id).addEventListener('blur',e=>{e.target.value=fmt(e.target.value);validate();}));
  document.addEventListener('keydown',keyEnter);
  reloadStages(); validate(); <?php if($justAdded):?>artist.focus();<?php endif;?>
});
</script>
</head><body>

<h1>Bonnaroo JSON Builder</h1>
<p>Need to make an edit to an existing schedule? Use the <a href="edit.php">JSON Editor</a> instead.</p>
<?=$msg?>

<form method="post">
<fieldset><legend>File</legend>
<label>Type:</label>
 <label><input type="radio" name="type" value="centeroo" <?=$type==='centeroo'?'checked':''?>>Centeroo</label>
 <label><input type="radio" name="type" value="outeroo"  <?=$type==='outeroo' ?'checked':''?>>Outeroo</label><br>
<label for="year">Year:</label>
 <input type="text" id="year" name="year" pattern="\d{4}" value="<?=htmlspecialchars($year)?>">
</fieldset>

<fieldset><legend>Entry</legend>
<label for="artist">Artist/Event:</label>
 <input type="text" id="artist" name="artist" tabindex="1" value="<?=htmlspecialchars($_POST['artist']??'')?>"><br>

<label for="start">Start:</label>
 <input type="text" id="start" name="start" size="6" tabindex="2" placeholder="3:45" value="<?=htmlspecialchars($_POST['start']??'')?>">
 <select name="startMer" tabindex="3"><?php foreach(['AM','PM']as$m)echo"<option".(($m==($_POST['startMer']??'PM'))?' selected':'').">$m</option>";?></select><br>

<label for="end">End:</label>
 <input type="text" id="end" name="end" size="6" tabindex="4" placeholder="5:00" value="<?=htmlspecialchars($_POST['end']??'')?>">
 <select name="endMer" tabindex="5"><?php foreach(['AM','PM']as$m)echo"<option".(($m==($_POST['endMer']??'PM'))?' selected':'').">$m</option>";?></select><br>

<label for="day">Day:</label>
 <select id="day" name="day" tabindex="6"><?php foreach(['Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']as$d)echo"<option value='$d'".($d===$daySel?' selected':'').">$d</option>";?></select><br>

<label for="stageSel">Location:</label>
 <select id="stageSel" name="stageSel" tabindex="7">
  <option value="">— choose location —</option><option value="<?=NEW_SENTINEL?>">(New Entry)</option>
  <?php foreach($locOpts as$st)echo"<option value='".htmlspecialchars($st)."'".($st===$locSel?' selected':'').">$st</option>";?>
 </select><br>

<label for="locationNew">New location:</label>
 <input type="text" id="locationNew" name="locationNew" tabindex="8" placeholder="enter new stage" disabled>
 <small>(required if “New Entry”)</small><br><br>

<button id="submitBtn" type="submit" tabindex="9" disabled>Add to JSON</button>
</fieldset>
</form>

<!-- Live preview -->
<div id="preview" style="margin-top:30px"></div>

</body></html>
