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
$centerooFile = "centeroo_$selectedYear.json";
$outerooFile = "outeroo_$selectedYear.json";

if (!file_exists($centerooFile) || !file_exists($outerooFile)) {
    die("Data for the selected year is not available.");
}

$centeroo = json_decode(file_get_contents($centerooFile), true);
$outeroo = json_decode(file_get_contents($outerooFile), true);

// Original function to convert 'g:i A' time to minutes past 12:00 AM (midnight = 0)
function timeToMinutes($time) {
    $parts = date_parse_from_format('g:i A', $time);
    // Note: date_parse_from_format treats 12:xx AM as hour 0 and 12:xx PM as hour 12.
    // This function essentially gives minutes from the start of the 12-hour cycle (AM/PM).
    // We might need a slightly different logic for 24-hour based sorting if day rollovers are complex.
    // Sticking to original logic for display consistency.
    return $parts['hour'] * 60 + $parts['minute'];
}

// Helper function for sorting: Converts 'g:i A' to minutes past midnight in a 24-hour context
function timeToMinutesSort($time) {
    $parts = date_parse_from_format('g:i A', $time);
    $hour = $parts['hour'];
    // Convert to 24-hour format for sorting comparison
    if ($parts['am'] && $hour == 12) { // 12:xx AM is hour 0
        $hour = 0;
    } elseif (!$parts['am'] && $hour != 12) { // PM hours other than 12:xx PM
        $hour += 12;
    }
    // If hour is 0, 1, 2, 3, 4, 5, it's considered "after midnight" for sorting purposes
    return $hour * 60 + $parts['minute'];
}


function minutesToTime($minutes) {
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    // Convert total minutes back to 12-hour format time for display
    $totalMinutesInDay = $h * 60 + $m; // Total minutes from 12:00 AM
    $hour24 = floor($totalMinutesInDay / 60) % 24;
    $minute = $totalMinutesInDay % 60;

    $ampm = $hour24 >= 12 ? 'PM' : 'AM';
    $hour12 = $hour24 % 12;
    if ($hour12 == 0) $hour12 = 12; // 0 hour is 12 AM, 12 hour is 12 PM

    return sprintf("%d:%02d %s", $hour12, $minute, $ampm);
}


function buildForm($data, $type) {
    echo "<div id='" . htmlspecialchars($type) . "' class='tabcontent'>";
    foreach ($data as $day => $locations) {
        echo "<h2>" . htmlspecialchars($day) . "</h2><div class='locations-grid'>";
        foreach ($locations as $location => $events) {
            if (empty($events)) continue;
            echo "<div class='location-block'><h3>" . htmlspecialchars($location) . "</h3>";
            // Sort events chronologically for initial display in the form
            usort($events, function($a, $b) {
                return timeToMinutes($a['start']) <=> timeToMinutes($b['start']);
            });
            foreach ($events as $event) {
                $label = htmlspecialchars("{$event['name']} ({$event['start']} - {$event['end']})");
                $value = htmlentities(json_encode([
                    'type' => $type,
                    'day' => $day,
                    'location' => $location,
                    'event' => $event // Pass the whole event array
                ]));
                echo "<label><input type='checkbox' name='selection[]' value='$value'> $label</label><br>";
            }
            echo "</div>";
        }
        echo "</div>";
    }
    echo "</div>";
}

// Custom sorting function for the planner view
function sortEvents($a, $b) {
    $startA = timeToMinutesSort($a->start); // Use 24-hour helper for logic
    $startB = timeToMinutesSort($b->start);

    // Define "after midnight" threshold (e.g., before 6 AM)
    $midnightThreshold = 6 * 60; // 6:00 AM in minutes from midnight

    // Check if events fall into the "after midnight" category (00:00 to 05:59)
    $a_is_after_midnight = ($startA >= 0 && $startA < $midnightThreshold);
    $b_is_after_midnight = ($startB >= 0 && $startB < $midnightThreshold);

    if ($a_is_after_midnight && !$b_is_after_midnight) {
        return 1; // a (after midnight) goes after b (regular time)
    } elseif (!$a_is_after_midnight && $b_is_after_midnight) {
        return -1; // a (regular time) goes before b (after midnight)
    } else {
        // Both are same side of midnight threshold, sort chronologically based on 24hr time
        return $startA <=> $startB;
    }
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
            vertical-align: top; /* Align content top */
        }
        td[rowspan] {
            background-color: #e0f7fa; /* Slightly different background for events */
            font-weight: bold;
        }
        .left-time-col {
            font-weight: bold;
            background-color: #f0f0f0;
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
            body {
                padding: 0;
                background-color: #fff;
            }
            .container {
                max-width: 100%;
                box-shadow: none;
            }
            #printButton, #pdfButton, #startOver, .tab, form, select#year, label[for='year'] {
                display: none; /* Hide buttons, tabs, and form in print view */
            }
            h1, h2, h3 {
                color: #000; /* Black text for printing */
            }
            table, th, td {
                border: 1px solid #000; /* Ensure borders are visible */
                font-size: 9pt; /* Adjust font size for print */
                padding: 4px;
            }
            td[rowspan] {
                background-color: #f0f0f0; /* Lighter background for print */
                font-weight: normal;
            }
            .left-time-col {
                background-color: #e0e0e0;
                font-weight: bold;
            }
            .day-section {
                page-break-inside: avoid; /* Try to keep tables on one page */
            }
            h3 {
                page-break-before: auto; /* Start new day potentially on new page */
            }

        }
    </style>
</head>
<body>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selection'])) {
    $selections = [];
    foreach($_POST['selection'] as $selJson) {
        // Decode JSON string, ensure event is treated as object
        $decoded = json_decode($selJson);
        if (is_object($decoded) && isset($decoded->event)) {
            // Ensure the event part itself is an object if it's not already
            if (is_array($decoded->event)) {
                $decoded->event = (object)$decoded->event;
            }
            $selections[] = $decoded;
        }
    }

    $grouped = [];
    foreach ($selections as $sel) {
        // Ensure we have objects with expected properties
        if (is_object($sel) && isset($sel->type, $sel->day, $sel->location, $sel->event) && is_object($sel->event)) {
            $grouped[$sel->type][$sel->day][$sel->location][] = $sel->event;
        }
    }

    // *** NEW: Sort events within each location for the planner view ***
    foreach ($grouped as $type => &$days) { // Use reference to modify directly
        foreach ($days as $day => &$locations) { // Use reference
            foreach ($locations as $loc => &$eventsList) { // Use reference
                usort($eventsList, 'sortEvents'); // Sort events using the custom function
            }
            unset($eventsList); // Break reference links
        }
        unset($locations);
    }
    unset($days);
    // *** END NEW ***


    // Calculate overall time range for each day AFTER sorting potentially changes order
    $timeGrid = [];
    foreach ($grouped as $type => $days) {
        foreach ($days as $day => $locations) {
            // Initialize min/max using a known range (e.g., 6 AM to 5:59 AM next day)
            $earliest = 6 * 60; // Start grid at 6 AM
            $latest = (24 + 5) * 60 + 59; // End grid at 5:59 AM next day
            $dayHasEvents = false;

            foreach ($locations as $events) {
                if (!empty($events)) $dayHasEvents = true;
                foreach ($events as $event) {
                    // Use the 24-hour helper for range calculation
                    $start = timeToMinutesSort($event->start);
                    $end = timeToMinutesSort($event->end);
                    // If end time is before start time (e.g., 11 PM - 1 AM), add 24 hours worth of minutes
                    if ($end < $start) {
                        $end += 24 * 60;
                    }

                    if ($start < $earliest) $earliest = $start;
                    if ($end > $latest) $latest = $end;
                }
            }

            if ($dayHasEvents) {
                // Adjust to 15-minute intervals, ensuring range covers all events
                $earliest = floor($earliest / 15) * 15;
                // Make sure latest covers the end time, ceil might cut off end of last event
                $latest = ceil($latest / 15) * 15;
                // Ensure latest is at least one slot after the earliest
                if ($latest <= $earliest) $latest = $earliest + 15;

                if (!isset($timeGrid[$day]) || $earliest < $timeGrid[$day]['start']) {
                    $timeGrid[$day]['start'] = $earliest;
                }
                if (!isset($timeGrid[$day]) || $latest > $timeGrid[$day]['end']) {
                    $timeGrid[$day]['end'] = $latest;
                }
            } else {
                // Handle case where a day might have been selected but has no events
                // Or just skip days with no events entirely later? For now, set a default.
                if (!isset($timeGrid[$day])) {
                    $timeGrid[$day] = ['start' => 6*60, 'end' => 7*60]; // Default 6 AM - 7 AM if no events
                }
            }
        }
    }


    echo "<div class='container' id='planner-content'>";
    echo "<h1>Your Custom Bonnaroo {$selectedYear} Planner</h1>";

    // Determine the order of days (e.g., Thursday, Friday, Saturday, Sunday)
    $dayOrder = ['Thursday', 'Friday', 'Saturday', 'Sunday']; // Adjust if needed
    $sortedDaysGrouped = [];
    foreach ($dayOrder as $day) {
        foreach ($grouped as $type => $days) {
            if (isset($days[$day])) {
                $sortedDaysGrouped[$type][$day] = $days[$day];
            }
        }
    }


    foreach ($sortedDaysGrouped as $type => $days) {
        echo "<h2 style='margin-top:40px;'>" . htmlspecialchars($type) . "</h2>";

        foreach ($days as $day => $locations) {
            // Skip generating table for a day if it has no events after filtering/selection
            if (!isset($timeGrid[$day])) continue;

            echo "<h3>" . htmlspecialchars($day) . "</h3>";
            echo "<table class='day-section' data-day='" . htmlspecialchars($day) . "'>";
            echo "<thead><tr><th>Time</th>";

            // Use only locations that have events for this day
            $stageNames = array_keys($locations);
            foreach ($stageNames as $stage) {
                echo "<th>" . htmlspecialchars($stage) . "</th>";
            }
            echo "</tr></thead><tbody>";

            $rowSpanTracker = []; // Track rowspan counts for each stage column

            // Loop through time slots based on calculated range for the day
            for ($time = $timeGrid[$day]['start']; $time < $timeGrid[$day]['end']; $time += 15) {
                echo "<tr>";
                // Display time in the first column using original minutesToTime
                echo "<td class='left-time-col'>" . minutesToTime($time) . "</td>";

                foreach ($stageNames as $stage) {
                    // If the current stage column is spanned by a previous event, decrement tracker and skip cell
                    if (isset($rowSpanTracker[$stage]) && $rowSpanTracker[$stage] > 0) {
                        $rowSpanTracker[$stage]--;
                        continue; // Skip rendering this td, it's covered by rowspan
                    }

                    $eventFound = null;
                    $eventStartMinute = -1; // Use sort helper time for matching
                    $eventEndMinute = -1;

                    // Check events for the current stage if they exist
                    if(isset($locations[$stage])) {
                        foreach ($locations[$stage] as $event) {
                            $start = timeToMinutesSort($event->start); // Use sort helper time
                            $end = timeToMinutesSort($event->end);
                            // Handle overnight events for duration calculation
                            if ($end < $start) {
                                $end += 24 * 60;
                            }

                            // Check if an event STARTS at this exact time slot
                            if ($start == $time) {
                                $eventFound = $event;
                                $eventStartMinute = $start;
                                $eventEndMinute = $end;
                                break; // Found the event starting now
                            }
                        }
                    }

                    if ($eventFound) {
                        // Calculate rowspan needed (duration in 15-min slots)
                        $durationMinutes = $eventEndMinute - $eventStartMinute;
                        $span = max(1, $durationMinutes / 15); // Ensure span is at least 1

                        // Set the rowspan tracker for this stage for future rows
                        $rowSpanTracker[$stage] = $span - 1;

                        // Output the cell with rowspan
                        echo "<td rowspan='{$span}'>" . htmlspecialchars($eventFound->name) . "<br><small>" . htmlspecialchars($eventFound->start) . " - " . htmlspecialchars($eventFound->end) . "</small></td>";
                    } else {
                        // No event starts here, and not spanned by a previous one, output empty cell
                        echo "<td></td>";
                    }
                } // End foreach stageNames

                echo "</tr>";
            } // End for loop through time slots

            echo "</tbody></table><br>";
        } // End foreach days
    } // End foreach types (Centeroo/Outeroo)

    echo "<button id='printButton' onclick='printPlanner()'>Print</button> ";
    echo "<button id='pdfButton' onclick='downloadPDF()'>Download as PDF</button><br><br>";
    echo "<a href='" . htmlspecialchars($_SERVER['PHP_SELF']) . "?year={$selectedYear}' id='startOver'>Start Over</a>";
    echo "</div>"; // End planner-content

} else {
    // Display initial form
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
            <button class='tablinks active' onclick=\"openTab(event, 'Centeroo')\">Centeroo</button>
            <button class='tablinks' onclick=\"openTab(event, 'Outeroo')\">Outeroo</button>
          </div>";
    echo "<form method='POST'>";
    buildForm($centeroo, 'Centeroo'); // Build Centeroo form
    buildForm($outeroo, 'Outeroo'); // Build Outeroo form
    echo "<br><button type='submit'>Build My Planner!</button></form>";
    echo "</div>"; // End container for form
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
        var targetTab = document.getElementById(tabName);
        if(targetTab) targetTab.style.display = "block"; // Make sure tab exists
        if(evt && evt.currentTarget) evt.currentTarget.className += " active";
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Ensure the first tab is displayed by default if no specific one is active
        var firstTabButton = document.querySelector('.tablinks');
        if (firstTabButton && !document.querySelector('.tablinks.active')) {
            openTab({currentTarget: firstTabButton}, 'Centeroo'); // Simulate click on first tab
        } else if (document.querySelector('.tablinks.active')){
            // If a tab is already active (e.g. state restored), make sure content is shown
            document.querySelector('.tablinks.active').click();
        }
        // Default to Centeroo if needed
        if (!document.querySelector('.tabcontent[style*="block"]')) {
            const centerooTab = document.getElementById('Centeroo');
            if (centerooTab) centerooTab.style.display = 'block';
        }

    });

    function printPlanner() {
        window.print();
    }

    function downloadPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF({ orientation: 'landscape' }); // Use landscape for wider tables
        const tables = document.querySelectorAll(".day-section");
        const plannerTitle = "<?php echo 'Bonnaroo Planner ' . $selectedYear; ?>";
        const plannerType = document.querySelector('h2') ? document.querySelector('h2').innerText : ''; // Get Centeroo/Outeroo

        doc.setFontSize(22);
        doc.text(plannerTitle, doc.internal.pageSize.getWidth() / 2, 15, { align: 'center' });
        // doc.setFontSize(18);
        // doc.text(plannerType, doc.internal.pageSize.getWidth() / 2, 25, { align: 'center' });


        let startY = 30; // Initial Y position for the first table

        tables.forEach((table, index) => {
            const day = table.getAttribute('data-day');
            const dayHeader = table.closest('.container').querySelector('h3:nth-of-type('+(index+1)+')').innerText; // More robust way? Maybe use data-day attr more directly
            const tableTitle = plannerType + " - " + day; // Combine type and day


            // Check if new page is needed before drawing table
            const pageHeight = doc.internal.pageSize.getHeight();
            const tableHeight = (table.rows.length + 1) * 10; // Estimate height
            if (startY + tableHeight > pageHeight - 20) { // Check if table fits with margin
                doc.addPage();
                startY = 20; // Reset Y for new page
            }


            doc.setFontSize(16);
            doc.text(dayHeader, 14, startY); // Use day name from H3
            startY += 8; // Space after day header

            doc.autoTable({
                html: table,
                startY: startY,
                theme: 'grid',
                headStyles: { fillColor: [106, 13, 173], textColor: [255, 255, 255], fontStyle: 'bold' },
                styles: { font: 'helvetica', fontSize: 8, cellPadding: 1.5, overflow: 'linebreak' }, // Smaller font, padding
                columnStyles: {
                    0: { cellWidth: 25 } // Wider time column
                    // Add other column widths if necessary
                },
                margin: { left: 10, right: 10 },
                didDrawPage: function (data) {
                    // Reset Y position if autotable creates new page
                    startY = data.cursor.y + 10; // Update startY for next table
                }
            });
            // Update startY based on where the last table finished.
            startY = doc.lastAutoTable.finalY + 10; // Add margin for next element


        });

        doc.save('Bonnaroo_Planner_<?php echo $selectedYear; ?>.pdf');
    }
</script>
</body>
</html>