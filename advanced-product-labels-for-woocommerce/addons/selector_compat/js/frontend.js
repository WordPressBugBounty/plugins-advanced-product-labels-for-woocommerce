var bapl_replacement;
(function ($){
    $(document).ready( function () {
        bapl_replacement = function() {
            $('.bapl_replacements .bapl_replace').each(function() {
                var element_replace = $(this);
                var post_selector = brlabelsSelectors.post_id;
                var image_selector = brlabelsSelectors.image;
                var title_selector = brlabelsSelectors.title;
                var post_id = $(this).data('id');
                post_selector = post_selector.replace('%ID%', post_id);
                var post_block = $(post_selector);
                if( post_block.length > 0 ) {
                    post_block.each(function () {
                        if( $(this).find('.berocket_better_labels_image').length == 0 && element_replace.find('.berocket_better_labels_image').length > 0 ) {
                            if( $(this).find(image_selector).length > 0 ) {
                                $(this).find(image_selector).first().before(element_replace.find('.berocket_better_labels_image').clone());
                                $(this).find(image_selector).first().parent().css('position', 'relative');
                            } else if( $(this).find('.wp-post-image').length > 0 ) {
                                $(this).find('.wp-post-image').first().before(element_replace.find('.berocket_better_labels_image').clone());
                                $(this).find('.wp-post-image').first().parent().css('position', 'relative');
                            } else if( $(this).find('img').length > 0 ) {
                                $(this).find('img').first().before(element_replace.find('.berocket_better_labels_image').clone());
                                $(this).find('img').first().parent().css('position', 'relative');
                            }
                        }
                        if( $(this).find('.berocket_better_labels_label').length == 0 && element_replace.find('.berocket_better_labels_label').length > 0 ) {
                            if( $(this).find(title_selector).length == 1 ) {
                                $(this).find(title_selector).first().before(element_replace.find('.berocket_better_labels_label').clone());
                            } else if( $(this).find('h1, h2, h3, h4, h4, h5').length == 1 ) {
                                $(this).find('h1, h2, h3, h4, h4, h5').first().before(element_replace.find('.berocket_better_labels_label').clone());
                            } else if( $(this).find('h1, h2, h3, h4, h4, h5').length > 1 ) {
                                const selectors = ['h1', 'h2', 'h3', 'h4', 'h4', 'h5'];
                                selectors.forEach(function(selector, index) {
                                    if( $(this).find(selector).length > 0 ) {
                                        $(this).find(selector).first().before(element_replace.find('.berocket_better_labels_label').clone());
                                        return false;
                                    }
                                    return true;
                                });
                            }
                        }
                    });
                }
            });
        }
        bapl_replacement();
    });

    function replaceBlocksFromHtml(html){
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var $src = $(doc).find('.bapl_replacements');
        if (!$src.length) return;

        var $dst = $('.bapl_replacements');
        if (!$dst.length) return;

        $src.find('.bapl_replace').each(function() {
            var id = $(this).data('id');
            if( $dst.find('.bapl_replace[data-id="'+id+'"]').length == 0 ) {
                $dst.append($(this));
            }
        });
    }

    $(document).ajaxComplete(function(event, xhr, settings){
        try {
            var url = (settings && settings.url) || '';
            if (/admin-ajax/i.test(url)) return;

            var ct = xhr.getResponseHeader && xhr.getResponseHeader('content-type') || '';
            var text = xhr.responseText || '';

            if (ct.toLowerCase().indexOf('text/html') !== -1 || /^\s*</.test(text)) {
                replaceBlocksFromHtml(text);
            }
            setTimeout(bapl_replacement, 100);
        } catch(e) {}
    });
})(jQuery);