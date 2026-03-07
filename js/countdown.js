/**
 * Season countdown timer for TVLW.
 * Targets both #season-countdown (homepage hero) and #season-countdown-navbar (navbar).
 * Reads the season end timestamp from data-end attribute and updates every 60 seconds.
 */
(function () {
  "use strict";

  // Collect all countdown elements (homepage hero + navbar)
  var ids = ["season-countdown", "season-countdown-navbar"];
  var elements = [];
  var endTimestamp = 0;

  for (var i = 0; i < ids.length; i++) {
    var el = document.getElementById(ids[i]);
    if (el) {
      elements.push(el);
      // Use the first data-end we find (both should have same value)
      if (!endTimestamp) {
        var ts = parseInt(el.getAttribute("data-end"), 10);
        if (ts && !isNaN(ts)) {
          endTimestamp = ts;
        }
      }
    }
  }

  if (elements.length === 0) return;

  if (!endTimestamp) {
    for (var j = 0; j < elements.length; j++) {
      elements[j].textContent = "--";
    }
    return;
  }

  function update() {
    var now = Math.floor(Date.now() / 1000);
    var diff = endTimestamp - now;

    var text;
    if (diff <= 0) {
      text = "Nouvelle saison imminente !";
      clearInterval(intervalId);
    } else {
      var jours = Math.floor(diff / 86400);
      var heures = Math.floor((diff % 86400) / 3600);
      var minutes = Math.floor((diff % 3600) / 60);
      text =
        jours + "j " + heures + "h " + minutes + "m (heure du serveur UTC+1)";
    }

    for (var k = 0; k < elements.length; k++) {
      elements[k].textContent = text;
    }
  }

  var intervalId;
  update();
  intervalId = setInterval(update, 60000);
})();
