function openTab(evt, tabName) {
    document.querySelectorAll("input[type='checkbox']").forEach(cb => cb.checked = false);
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

/* Select / deselect only the checkboxes in the visible tab */
function toggleSelection(state) {
    const activeTab = document.querySelector(".tabcontent[style*='block']");
    if (!activeTab) return;
    activeTab.querySelectorAll("input[type='checkbox']").forEach(cb => cb.checked = state);
}

//–– Localize the “last-updated” time for each visitor ––
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('last-updated');
    if (!el) return;
    const dt = new Date(el.getAttribute('datetime'));
    // toLocaleString() will format e.g. "5/3/2025, 4:16:52 PM" in your user's locale
    el.textContent = dt.toLocaleString();
});