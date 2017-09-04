(function($) {
	$(function() {
		// Get aceepted revision index in revisions data array
		var revisionData = _wpRevisionsSettings.revisionData,
			aceeptedIndex = revisionData.length - 1;
		for (var i = aceeptedIndex; i >= 0; i--) {
			revision = revisionData[i];
			if (revision.current) {
				aceeptedIndex = i;
				break;
			}
		}

		// Mark the aceepted revision visually
		$('.revisions-tickmarks div:nth-child(' + (aceeptedIndex + 1) + ')').css({
			borderLeft: '3px solid crimson'
		});
		var aceeptedPosition = (aceeptedIndex + 1) / revisionData.length * 100,
		$pendingChangesTickmarks = $('<span class="fc-current-revision-tickmark">');
		$pendingChangesTickmarks.css({
			position: 'absolute',
			height: '100%',
			'-webkit-box-sizing': 'border-box',
			'-moz-box-sizing': 'border-box',
			boxSizing: 'border-box',
			display: 'block',
			left: aceeptedPosition + '%',
			width: (100 - aceeptedPosition) + '%',
			border: 'none',
			backgroundColor: 'lightgray',
		});
		$('.revisions-tickmarks').prepend($pendingChangesTickmarks);
	});
})(jQuery);
