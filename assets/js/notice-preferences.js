/* Native WordPress dismissal; only explicitly marked state reminders persist. */
jQuery(function ($) {
    $(document).on('click', '[data-yoohw-cos-notice] .notice-dismiss', function () {
        var notice = $(this).closest('[data-yoohw-cos-notice]');
        $.post(yoohwCosNoticePreferences.url, {
            action: 'yoohw_cos_dismiss_notice',
            nonce: yoohwCosNoticePreferences.nonce,
            key: notice.attr('data-yoohw-cos-notice'),
            revision: notice.attr('data-yoohw-cos-revision')
        });
    });
});
