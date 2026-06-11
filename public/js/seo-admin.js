(function ($) {
    'use strict';
    function text(v)
    {
        return $('<div>').text(v || '').text(); }
    function updatePreview()
    {
        var title = $('#seo_meta_title').val() || $('#content_title,#product_title').first().val() || 'Search preview title';
        var desc = $('#seo_meta_description').val() || 'Search preview description.';
        $('#seo_preview_title').text(title); $('#seo_preview_desc').text(desc);
        $('#seo_title_count').text(($('#seo_meta_title').val() || '').length); $('#seo_desc_count').text(($('#seo_meta_description').val() || '').length);
    }
    function renderAnalysis(data)
    {
        $('#devflow-seo-score').text((data.score || 0) + '/100');
        var html = '<ul class="list-unstyled">';
        $.each(data.checks || [], function (_, c) {
            html += '<li><i class="fa ' + (c.pass ? 'fa-check text-green' : 'fa-times text-red') + '"></i> ' + text(c.label) + '</li>'; });
        html += '</ul>';
        $('#devflow-seo-analysis-results').html(html);
    }
    function analyze()
    {
        var canonical = $('[name$="[seo][canonical_url]"]').val() || $('#seo_preview_url').text() || '';

        var socialImage = $('[name$="[seo][facebook_image]"]').val()
        || $('[name$="[seo][twitter_image]"]').val()
        || $('[name$="[featured_image]"]').val()
        || '';

        $.post('/admin/plugin/simple-seo/analyze/', {
            title: $('#seo_meta_title').val() || $('#content_title,#product_title').first().val(),
            description: $('#seo_meta_description').val(),
            body: $('#content_body,#product_body').first().val(),
            focus: $('#seo_focus_keyphrase').val(),
            canonical: canonical,
            social_image: socialImage
        }).done(renderAnalysis).fail(function () {
            renderAnalysis({score:0, checks:[{pass:false,label:'Analysis request failed.'}]});
        });
    }

    $(document).on('input', '#seo_meta_title,#seo_meta_description,#content_title,#product_title', updatePreview);

    $(document).on('click', '#devflow-seo-run-analysis,#devflow-seo-side-analysis', analyze);

    $(document).on('click', '.seo-elfinder', function () {
        var target = $(this).data('target');
        if (window.cms_upload_image) {
            window.cms_upload_image(target); return; }
        var url = prompt('Image URL'); if (url) {
            $(target).val(url); }
    });

    $(document).on('submit', '#devflow-seo-indexing-form', function (e) {
        e.preventDefault();

        var $form = $(this);
        var url = window.DEVFLOW_SEO_INDEXING_URL || '/admin/plugin/simple-seo/indexing/submit/';

        $.ajax({
            url: url,
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json'
        }).done(function (r) {
            var success = r && r.ok === true;

            $('#devflow-seo-indexing-result').html(
                '<div class="alert alert-' +
                (success ? 'success' : 'danger') +
                '">' +
                text((r && r.message) ? r.message : 'Unknown response.') +
                '</div>'
            );
        }).fail(function (xhr) {
            var message = 'Indexing request failed.';

            if (xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }

            $('#devflow-seo-indexing-result').html(
                '<div class="alert alert-danger">' +
                text(message) +
                '</div>'
            );
        });
    });

    $(document).on('click', '#devflow-seo-test-gsc', function () {
        $.post($(this).data('url')).done(function (r) {
            $('#devflow-seo-gsc-result').html('<div class="alert alert-' + (r.ok ? 'success' : 'danger') + '">' + text(r.message) + '</div>'); });
    });

    function renderSubmissionResult($form, response)
    {
        var type = response && response.ok ? 'success' : 'danger';
        var message = response && response.message ? response.message : 'Request failed.';

        $form.find('.devflow-seo-result').html(
            '<div class="alert alert-' + type + '">' + text(message) + '</div>'
        );
    }
    $(document).on(
        'submit',
        '#devflow-seo-submit-url-form, #devflow-seo-submit-urls-form, #devflow-seo-submit-sitemap-form',
        function (e) {
            e.preventDefault();

            var $form = $(this);

            $form.find('.devflow-seo-result').html(
                '<div class="alert alert-info">Submitting...</div>'
            );

            $.post($form.attr('action'), $form.serialize())
                .done(function (response) {
                    renderSubmissionResult($form, response);
                })
                .fail(function () {
                    renderSubmissionResult($form, {
                        ok: false,
                        message: 'Submission request failed.'
                    });
                });
        }
    );

    function siteUrl(path)
    {
        var base = window.DEVFLOW_SITE_URL || window.location.origin;

        path = path || '';

        return base.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
    }

    function currentRoutePath()
    {
        return $('#seo_route_path,.js-seo-route-path,[name="route_path"]').first().val() || '';
    }

    function currentPageRoute()
    {
        var $canonical = $('[name$="[seo][canonical_url]"]').first();
        var locale = $canonical.data('default-locale') || 'en';

        var $route = $('[name="route[' + locale + ']"]').first();

        if (!$route.length) {
            $route = $('[name^="route["]').first();
        }

        return $route.length ? $route.val() : '';
    }

    function currentSlug()
    {
        return $('#content_slug,#product_slug,[name="content_field[slug]"],[name="product_field[slug]"]').first().val()
            || $('#content_slug_preview,#product_slug_preview,.slug-preview').first().text()
            || '';
    }

    function updateCanonicalPreview()
    {
        var $canonical = $('[name$="[canonical_url]"]').first();

        if (!$canonical.length || $canonical.val()) {
            return;
        }

        var type = $canonical.data('entity-type') || '';
        var path = '';

        if (type === 'route') {
            path = currentRoutePath();
        } else if (type === 'page') {
            path = currentPageRoute();
        } else {
            var slug = currentSlug();

            if (!slug) {
                return;
            }

            path = slug.replace(/^\/+|\/+$/g, '') + '/';

            if ($('#product_title,#product_slug,[name="product_field[slug]"]').length) {
                path = 'product/' + path;
            }
        }

        if (!path) {
            return;
        }

        path = path.replace(/^\/+|\/+$/g, '') + '/';

        var url = siteUrl(path);

        $canonical.attr('placeholder', url);
        $('#seo_preview_url').text(url);
    }

    $(document).on(
        'input keyup change',
        '#seo_meta_title,#seo_meta_description,#content_title,#product_title,#content_slug,#product_slug,[name="content_field[slug]"],[name="product_field[slug]"],[name^="route["],#seo_route_path,.js-seo-route-path,[name="route_path"]',
        function () {
            updatePreview();
            updateCanonicalPreview();
        }
    );

    $(updatePreview);
    $(updateCanonicalPreview);
})(jQuery);
