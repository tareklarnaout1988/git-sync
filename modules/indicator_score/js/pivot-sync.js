(function (Drupal, once) {
  function syncHeaderWidths() {
    var bodyTable = document.getElementById('pivot-body');
    var headerTable = document.getElementById('pivot-header');
    if (!bodyTable || !headerTable) return;

    // Largeur de la colonne Country (1ère cellule du body)
    var firstBodyRow = bodyTable.tBodies[0]?.rows[0];
    if (!firstBodyRow) return;

    var countryTd = firstBodyRow.cells[0];
    var countryTh = headerTable.tHead.rows[0].cells[0]; // le <th rowspan="3">Country
    if (countryTd && countryTh) {
      var w = countryTd.getBoundingClientRect().width;
      countryTd.style.width = w + 'px';
      countryTh.style.width = w + 'px';
    }

    // Largeurs des colonnes indicateurs (3e ligne d'entête)
    var indicatorHeaderRow = headerTable.querySelector('#pivot-header-indicators');
    if (!indicatorHeaderRow) return;

    var bodyTds = Array.from(firstBodyRow.cells).slice(1); // tds après Country
    var headerThs = Array.from(indicatorHeaderRow.cells);

    // Assigner largeur th (indicator) = largeur td correspondante
    var count = Math.min(bodyTds.length, headerThs.length);
    for (var i = 0; i < count; i++) {
      var bw = bodyTds[i].getBoundingClientRect().width;
      headerThs[i].style.width = bw + 'px';
    }

    // Ajuster la largeur des tables pour éviter reflow
    // somme des largeurs + country
    var total = (countryTd?.getBoundingClientRect().width || 0);
    for (var j = 0; j < count; j++) {
      total += bodyTds[j].getBoundingClientRect().width;
    }
    headerTable.style.width = total + 'px';
    bodyTable.style.width = total + 'px';
  }

  function bindScrollSync() {
    var bodyWrap = document.getElementById('pivot-body-wrapper');
    var headerWrap = document.getElementById('pivot-header-wrapper');
    if (!bodyWrap || !headerWrap) return;

    bodyWrap.addEventListener('scroll', function () {
      headerWrap.scrollLeft = bodyWrap.scrollLeft; // synchro horizontale
    }, { passive: true });
  }

  Drupal.behaviors.pivotSync = {
    attach: function (context) {
      once('pivotSyncInit', 'html', context).forEach(function () {
        // Synchroniser au chargement
        syncHeaderWidths();
        bindScrollSync();
        // Recalcule sur redimensionnement
        var ro = new ResizeObserver(function () { syncHeaderWidths(); });
        ro.observe(document.getElementById('pivot-body'));
        window.addEventListener('resize', syncHeaderWidths);
      });
    }
  };
})(Drupal, once);
