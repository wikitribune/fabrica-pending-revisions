(function($) {
	$(function() {

		// Disable update/publish button if post is locked and user is not editor
		if (fpcData.editingMode === 'locked' && !fpcData.canUserPublishPosts) {
			$('#publish').attr('disabled', true);
			$('#major-publishing-actions').show();
		}

		// Show spinner when saving pending changes
		$('#pending-changes-submit').click(function(event) {
			$("#major-publishing-actions .spinner").addClass("is-active");
		});
	});
})(jQuery);
