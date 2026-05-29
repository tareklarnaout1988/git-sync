(function ($, Drupal, once) {
  Drupal.behaviors.businessPivot = {
    attach: function (context) {
      once('businessPivotDT', '#pivot-table', context).forEach(function (el) {
        if (!$.fn.dataTable) return;

        $(el).DataTable({
          scrollX: true,
          paging: false,
          searching: true,
          ordering: false,
          info: false,
          fixedHeader: {
            header: true
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
