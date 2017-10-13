(function($) {
	$(function() {

		// Get accepted revision index in revisions data array
		var revisionData = _wpRevisionsSettings.revisionData,
			acceptedIndex = revisionData.length - 1;
		for (var i = acceptedIndex; i >= 0; i--) {
			revision = revisionData[i];
			if (revision.current) {
				if (revision.pending) {

					// When the last revision is an autosave WP sets the current property in the last non-autosave revision in the data sent to JS (after the `wp_prepare_revision_for_js` hook where we set the correct current revision)
					revision.current = false;
					var revisionsCollection = wp.revisions.view.frame.model.revisions,
						revisionModel = revisionsCollection.models[revisionsCollection.length - 1];
					if (revisionModel) {
						revisionModel.attributes.current = false;
					}
					continue;
				}
				acceptedIndex = i;
				break;
			}
		}
		if (acceptedIndex >= revisionData.length - 1) { return; }

		// Mark the accepted revision visually
		$('.revisions-tickmarks div:nth-child(' + (acceptedIndex + 1) + ')').css({
			borderLeft: '3px solid #46b450'
		});
		var acceptedPosition = acceptedIndex / (revisionData.length - 1) * 100,
			$pendingChangesTickmarks = $('<span class="fcr-current-revision-tickmark">');
		$pendingChangesTickmarks.css({
			position: 'absolute',
			height: '100%',
			'-webkit-box-sizing': 'border-box',
			'-moz-box-sizing': 'border-box',
			boxSizing: 'border-box',
			display: 'block',
			left: acceptedPosition + '%',
			width: (100 - acceptedPosition) + '%',
			border: 'none',
			background: 'repeating-linear-gradient(-60deg, #ddd, #ddd 9px, #f7f7f7 10px, #f7f7f7 17px)',
			pointerEvents: 'none',
		});
		$('.revisions-tickmarks').prepend($pendingChangesTickmarks);
	});
})(jQuery);
