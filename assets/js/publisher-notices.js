(function (wp) {
    if (!wp || !wp.data || !wp.url || !wp.apiFetch) {
        return;
    }

    if (window.freespokePublisherNoticesInitialized) {
        return;
    }

    window.freespokePublisherNoticesInitialized = true;

    var noticeId = "freespoke_publisher_notice";
    var noticePath = window.freespokePublisherNoticePath || "/freespoke/v1/publisher-latest-error";
    var unchecked = true;

    wp.data.subscribe(function () {
        var editor = wp.data.select("core/editor");

        if (editor.isSavingPost()) {
            unchecked = false;
            return;
        }

        if (unchecked) {
            return;
        }

        unchecked = true;

        var postId = editor.getCurrentPostId();

        if (!postId) {
            return;
        }

        var path = wp.url.addQueryArgs(noticePath, { id: postId });

        wp.apiFetch({ path })
            .then(function (response) {
                var dispatcher = wp.data.dispatch("core/notices");

                if (response && response.message) {
                    dispatcher.createNotice("warning", response.message, {
                        id: noticeId,
                        isDismissible: true,
                    });
                } else {
                    dispatcher.removeNotice(noticeId);
                }
            })
            .catch(function () {
                // Silently swallow errors to avoid console noise when offline.
            });
    });
})(window.wp);
