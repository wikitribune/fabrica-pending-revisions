(function($) {
	$(function() {
		// Get accepted revision index in revisions data array
		var revisionData = _wpRevisionsSettings.revisionData,
			acceptedIndex = revisionData.length - 1;
		for (var i = acceptedIndex; i >= 0; i--) {
			revision = revisionData[i];
			if (revision.current) {
				acceptedIndex = i;
				break;
			}
		}

		// Mark the accepted revision visually
		$('.revisions-tickmarks div:nth-child(' + (acceptedIndex + 1) + ')').css({
			borderLeft: '3px solid crimson'
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
			backgroundColor: 'lightgray',
		});
		$('.revisions-tickmarks').prepend($pendingChangesTickmarks);
	});
})(jQuery);
