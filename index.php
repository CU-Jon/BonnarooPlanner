<?php
// Detect available years dynamically
$years = [];
foreach (glob("centeroo_*.json") as $file) {
    if (preg_match('/centeroo_(\d{4})\.json$/', $file, $matches)) {
        $years[] = $matches[1];
    }
}
sort($years);

$selectedYear = $_GET['year'] ?? (end($years) ?: '2025');
$centerooFile = "centeroo_{$selectedYear}.json";
$outerooFile = "outeroo_{$selectedYear}.json";

if (!file_exists($centerooFile) || !file_exists($outerooFile)) {
    die("Data for the selected year is not available.");
}

$centeroo = json_decode(file_get_contents($centerooFile), true);
$outeroo = json_decode(file_get_contents($outerooFile), true);

function timeToMinutes($time) {
    $parts = date_parse_from_format('g:i A', $time);
    return $parts['hour'] * 60 + $parts['minute'];
}

function minutesToTime($minutes) {
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h = $h % 12;
    if ($h == 0) $h = 12;
    return sprintf("%d:%02d %s", $h, $m, $ampm);
}

function buildForm($data, $type) {
    echo "<div id='" . htmlspecialchars($type) . "' class='tabcontent'>";
    foreach ($data as $day => $locations) {
        echo "<h2>" . htmlspecialchars($day) . "</h2><div class='locations-grid'>";
        foreach ($locations as $location => $events) {
            if (empty($events)) continue;
            echo "<div class='location-block'><h3>" . htmlspecialchars($location) . "</h3>";
            foreach ($events as $event) {
                $label = htmlspecialchars("{$event['name']} ({$event['start']} - {$event['end']})");
                $value = htmlentities(json_encode([
                    'type' => $type,
                    'day' => $day,
                    'location' => $location,
                    'event' => $event
                ]));
                echo "<label><input type='checkbox' name='selection[]' value='{$value}'> {$label}</label><br>";
            }
            echo "</div>";
        }
        echo "</div>";
    }
    echo "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bonnaroo Planner</title>
<link href="https://fonts.googleapis.com/css2?family=Concert+One&display=swap" rel="stylesheet">
<style>
body {
    font-family: 'Concert One', cursive;
    background-color: #f0f8ff;
    color: #333;
    padding: 20px;
    display: flex;
    justify-content: center;
    align-items: center;
    flex-direction: column;
    min-height: 100vh;
    text-align: center;
}
.container {
    max-width: 1200px;
    width: 100%;
}
h1, h2, h3, h4 {
    color: #6A0DAD;
}
table {
    width: 100%;
    margin: 0 auto 20px auto;
    border-collapse: collapse;
}
th, td {
    padding: 10px;
    text-align: center;
    border: 1px solid #ccc;
}
.tab {
    overflow: hidden;
    border-bottom: 2px solid #6A0DAD;
    margin-bottom: 20px;
    display: flex;
    justify-content: center;
}
.tab button {
    background-color: #FFD700;
    border: none;
    outline: none;
    cursor: pointer;
    padding: 14px 16px;
    transition: 0.3s;
    font-size: 17px;
    font-family: 'Concert One', cursive;
}
.tab button:hover {
    background-color: #FF69B4;
}
.tab button.active {
    background-color: #32CD32;
}
.tabcontent {
    display: none;
    padding: 20px 0;
}
label {
    display: block;
    margin: 5px 0;
}
select#year {
    background-color: #FFD700;
    border: 2px solid #6A0DAD;
    border-radius: 8px;
    font-family: 'Concert One', cursive;
    font-size: 16px;
    padding: 8px 12px;
    margin-left: 10px;
    transition: background-color 0.3s, transform 0.2s;
    cursor: pointer;
}
select#year:hover {
    background-color: #FF69B4;
}
.locations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
    text-align: left;
}
.location-block {
    background: #fff;
    border: 1px solid #ccc;
    padding: 10px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}
.location-block h3 {
    margin-top: 0;
    font-size: 1.2em;
    color: #FF69B4;
}
button[type='submit'], button#printButton, button#pdfButton, a#startOver {
    background-color: #6A0DAD;
    color: white;
    font-family: 'Concert One', cursive;
    font-size: 20px;
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background-color 0.3s, transform 0.2s;
    margin: 10px;
    text-decoration: none;
}
button:hover, a#startOver:hover {
    background-color: #FF69B4;
    transform: scale(1.05);
}
@media print {
    #printButton, #pdfButton, #startOver {
        display: none;
    }
}
</style>
</head>
<body>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selection'])) {
    $selections = array_map('json_decode', $_POST['selection']);
    $grouped = [];

    foreach ($selections as $sel) {
        if (is_object($sel) && isset($sel->type, $sel->day, $sel->location, $sel->event)) {
            $grouped[$sel->type][$sel->day][$sel->location][] = $sel->event;
        }
    }

    $timeGrid = [];
    foreach ($grouped as $type => $days) {
        foreach ($days as $day => $locations) {
            $earliest = 1440;
            $latest = 0;
            foreach ($locations as $events) {
                foreach ($events as $event) {
                    $start = timeToMinutes($event->start);
                    $end = timeToMinutes($event->end);
                    if ($start < $earliest) $earliest = $start;
                    if ($end > $latest) $latest = $end;
                }
            }
            $earliest = floor($earliest / 15) * 15;
            $latest = ceil($latest / 15) * 15;
            $timeGrid[$day] = ['start' => $earliest, 'end' => $latest];
        }
    }

    echo "<div class='container' id='planner-content'>";
    echo "<h1>Your Custom Bonnaroo {$selectedYear} Planner</h1>";

    foreach ($grouped as $type => $days) {
        echo "<h2 style='margin-top:40px;'>" . htmlspecialchars($type) . "</h2>";

        foreach ($days as $day => $locations) {
            echo "<h3>" . htmlspecialchars($day) . "</h3>";
            echo "<table class='day-section' data-day='" . htmlspecialchars($day) . "'>";
            echo "<thead><tr><th>Time</th>";

            $stageNames = array_keys($locations);
            foreach ($stageNames as $stage) {
                echo "<th>" . htmlspecialchars($stage) . "</th>";
            }
            echo "</tr></thead><tbody>";

            $rowSpanTracker = [];

            for ($time = $timeGrid[$day]['start']; $time < $timeGrid[$day]['end']; $time += 15) {
                echo "<tr>";
                echo "<td class='left-time-col'>" . minutesToTime($time) . "</td>";

                foreach ($stageNames as $stage) {
                    if (isset($rowSpanTracker[$stage]) && $rowSpanTracker[$stage] > 0) {
                        $rowSpanTracker[$stage]--;
                        continue;
                    }

                    $eventFound = null;
                    foreach ($locations[$stage] as $event) {
                        $start = timeToMinutes($event->start);
                        $end = timeToMinutes($event->end);
                        if ($start == $time) {
                            $eventFound = $event;
                            $rowSpanTracker[$stage] = ($end - $start) / 15 - 1;
                            break;
                        }
                    }

                    if ($eventFound) {
                        $span = ($end - $start) / 15;
                        echo "<td rowspan='{$span}'>" . htmlspecialchars($eventFound->name) . "<br><small>" . htmlspecialchars($eventFound->start) . " - " . htmlspecialchars($eventFound->end) . "</small></td>";
                    } else {
                        echo "<td></td>";
                    }
                }

                echo "</tr>";
            }

            echo "</tbody></table><br>";
        }
    }

    echo "<button id='printButton' onclick='printPlanner()'>Print</button> ";
    echo "<button id='pdfButton' onclick='downloadPDF()'>Download as PDF</button><br><br>";
    echo "<a href='" . htmlspecialchars($_SERVER['PHP_SELF']) . "?year={$selectedYear}' id='startOver'>Start Over</a>";
    echo "</div>";

} else {
    echo "<div class='container'>";
    echo "<h1>Select Your Bonnaroo {$selectedYear} Events</h1>";
    echo "<form method='GET' style='margin-bottom: 20px;'>
            <label for='year'>Select Year:</label>
            <select name='year' id='year' onchange='this.form.submit()'>";
    foreach ($years as $year) {
        $selected = $year == $selectedYear ? " selected" : "";
        echo "<option value='{$year}'{$selected}>{$year}</option>";
    }
    echo "</select>
          </form>";
    echo "<div class='tab'>
            <button class='tablinks' onclick=\"openTab(event, 'Centeroo')\">Centeroo</button>
            <button class='tablinks' onclick=\"openTab(event, 'Outeroo')\">Outeroo</button>
          </div>";
    echo "<form method='POST'>";
    buildForm($centeroo, 'Centeroo');
    buildForm($outeroo, 'Outeroo');
    echo "<br><button type='submit'>Build My Planner!</button></form>";
    echo "</div>";
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.23/jspdf.plugin.autotable.min.js"></script>
<script>
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tabcontent");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tablinks");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}

document.addEventListener('DOMContentLoaded', function() {
    var tab = document.querySelector('.tablinks');
    if (tab) tab.click();
});

function printPlanner() {
    window.print();
}

function downloadPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    const tables = document.querySelectorAll(".day-section");

    tables.forEach((table, index) => {
        if (index > 0) doc.addPage();
        const day = table.getAttribute('data-day');
        doc.setFontSize(18);
        doc.text(day, 15, 20);
        doc.autoTable({
            html: table,
            startY: 30,
            theme: 'grid',
            headStyles: { fillColor: [106, 13, 173] },
            styles: { font: 'helvetica', fontSize: 9 },
            margin: { top: 20 }
        });
    });

    doc.save('Bonnaroo_Planner_<?php echo $selectedYear; ?>.pdf');
}
</script>
</body>
</html>
