/**
 * Season countdown timer for TVLW homepage.
 * Reads the season end timestamp from #season-countdown[data-end]
 * and updates the display every 60 seconds.
 */
(function () {
  "use strict";

  var el = document.getElementById("season-countdown");
  if (!el) return;

  var endTimestamp = parseInt(el.getAttribute("data-end"), 10);
  if (!endTimestamp || isNaN(endTimestamp)) {
    el.textContent = "--";
    return;
  }

  function update() {
    var now = Math.floor(Date.now() / 1000);
    var diff = endTimestamp - now;

    if (diff <= 0) {
      el.textContent = "Nouvelle saison imminente !";
      clearInterval(intervalId);
      return;
    }

    var jours = Math.floor(diff / 86400);
    var heures = Math.floor((diff % 86400) / 3600);
    var minutes = Math.floor((diff % 3600) / 60);

    el.textContent = jours + "j " + heures + "h " + minutes + "m";
  }

  var intervalId;
  update();
  intervalId = setInterval(update, 60000);
})();
