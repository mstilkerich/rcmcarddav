$(document).ready(function() {
  var legend = $('#preferences-details fieldset.advanced legend'),
    toggle = $('<a href="#toggle"></a>')
    .text('Toggle advanced options')
    .attr('title', 'Toggle advanced')
    .addClass('advanced-toggle');

  legend.click(function(e) {
    toggle.html($(this).hasClass('collapsed') ? '&#9650;' : '&#9660;');

    $(this).toggleClass('collapsed')
      .closest('fieldset').children('.propform').toggle()
  }).append(toggle).addClass('collapsed')

  // this magically fixes incorrect position of toggle link created above in Firefox 3.6
  if (bw.mz)
    legend.parents('form').css('display', 'inline');
})
