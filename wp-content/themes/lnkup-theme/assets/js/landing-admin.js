(function ($) {
  'use strict';

  function bindMedia($root) {
    $root.on('click', '.lnkup-landing-media__select', function (e) {
      e.preventDefault();
      var $wrap = $(this).closest('[data-lnkup-media]');
      var frame = wp.media({
        title: 'Select image',
        button: { text: 'Use image' },
        multiple: false
      });
      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        var url = (attachment.sizes && attachment.sizes.medium)
          ? attachment.sizes.medium.url
          : attachment.url;
        $wrap.find('.lnkup-landing-media__input').val(attachment.id);
        $wrap.find('.lnkup-landing-media__preview').attr('src', url).prop('hidden', false);
        $wrap.find('.lnkup-landing-media__clear').prop('hidden', false);
      });
      frame.open();
    });

    $root.on('click', '.lnkup-landing-media__clear', function (e) {
      e.preventDefault();
      var $wrap = $(this).closest('[data-lnkup-media]');
      $wrap.find('.lnkup-landing-media__input').val('0');
      $wrap.find('.lnkup-landing-media__preview').attr('src', '').prop('hidden', true);
      $(this).prop('hidden', true);
    });
  }

  $(function () {
    bindMedia($(document));
  });
})(jQuery);
