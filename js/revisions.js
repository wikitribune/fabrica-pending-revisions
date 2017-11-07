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
					var revisionModel = wp.revisions.view.frame.model.revisions.models[i];
					if (revisionModel) {
						revisionModel.attributes.current = false;
					}
					continue;
				}
				acceptedIndex = i;
				break;
			}
		}

		// Mark the accepted revision visually
		if (acceptedIndex < revisionData.length - 1) {
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
		}

		// Change columns' header cards
		var $revisionsHeaders = $('<div>', {class: 'revisions-headers'});
		$('.revisions-controls').append($revisionsHeaders);
		var renderColumnHeader = function(side, revision) {

			// Render header fields
			var $header = $('<div>', {
				class: 'revisions-headers__' + side,
				html: [

					// Title
					$('<div>', {
						class: 'revisions-headers__title',
						html: [
							$('<span>', {class: 'revisions-headers__type'}),
							$('<span>', {
								class: 'revisions-headers__id',
								text: 'Revision ID ' + revision.id
							})
						]
					}),

					// Submission date/time
					$('<div>', {
						class: 'revisions-headers__date',
						html: [
							$('<span>', {text: 'submitted '}),
							$('<span>', {
								class: 'revisions-headers__date-ago',
								text: revision.timeAgo + ' '
							}),
							$('<span>', {
								class: 'revisions-headers__date-long',
								text: revision.date
							})
						]
					}),

					// Author
					$('<div>', {
						class: 'revisions-headers__author',
						html: [
							$('<span>', {text: 'by '}),
							$('<span>', {
								class: 'revisions-headers__author-name',
								text: revision.author.name + ' '
							}),
							$('<span>', {
								class: 'revisions-headers__author-role',
								text: '(' + revision.author.role + ')'
							})
						]
					}),

					// Note
					((!revision.note && !revision.sourceRevisionID) ? null : $('<div>', {
						class: 'revisions-headers__note',
						html: [
							((!revision.note) ? null : $('<span>', {
								class: 'revisions-headers__note-caption',
								text: 'Note: '
							})),
							$('<span>', {
								html: [
									((!revision.note) ? null : $('<div>', {
										class: 'revisions-headers__note-text',
										html: revision.note + ' ' // Set on `html` to decode possible HTML entities
									})),
									((!revision.sourceRevisionID) ? null : $('<div>', {
										class: 'revisions-headers__based-on',
										html: [
											$('<span>', {text: 'based on Revision '}),
											$('<span>', {text: revision.sourceRevisionID})
										]
									}))
								]
							})
						]
					})),

					// Buttons
					$('<div>', {
						class: 'revisions-buttons',
						html: [
							$('<input>', {
								type: 'button',
								value: 'Edit',
								class: 'revisions-buttons__button revisions-buttons__button--edit',
							}),
							$('<input>', {
								type: 'button',
								value: 'Preview',
								class: 'revisions-buttons__button revisions-buttons__button--preview',
							}),
							$('<input>', {
								type: 'button',
								value: 'Restore',
								class: 'revisions-buttons__button revisions-buttons__button--restore',
							}),
							$('<input>', {
								type: 'button',
								value: 'Publish',
								class: 'revisions-buttons__button revisions-buttons__button--publish',
							}),
						]
					})
				]
			});

			// Set title class and text according to revision status
			$revisionType = $('.revisions-headers__type', $header);
			if (revision.current) {
				$revisionType.addClass('revisions-headers__type--current')
					.text('Current Published ');
			} else if (revision.pending) {
				$revisionType.addClass('revisions-headers__type--pending')
					.text('Pending ');
			}

			var $editButton = $('.revisions-buttons__button--edit', $header),
				$previewButton = $('.revisions-buttons__button--preview', $header),
				$restoreButton = $('.revisions-buttons__button--restore', $header),
				$publishButton = $('.revisions-buttons__button--publish', $header);

			// Buttons enabling and disabling
			var latestRevision = revisionData[revisionData.length - 1];
			if (revision == latestRevision) {
				$restoreButton.attr('disabled', true);
				$restoreButton.addClass('revisions-buttons__button--disabled');
			}
			if (!revision.pending) {
				$publishButton.hide();
			} else if (revision.current) {
				$publishButton.attr('disabled', true);
				$publishButton.addClass('revisions-buttons__button--disabled');
			}

			// Buttons actions
			$editButton.click(function() { document.location = revision.editUrl; });
			$previewButton.click(function() { document.location = revision.previewUrl; });
			$restoreButton.click(function() { document.location = revision.restoreUrl; });
			$publishButton.click(function() { document.location = revision.publishUrl; });

			return $header;
		};
		var renderColumnHeaders = function(value, values) {
			if (!values || values.length <= 0) {
				values = [value - 1, value];
			}
			if (values[0] == values[1] || values[0] < 0) { return; }

			var $headerFrom = renderColumnHeader('from', revisionData[values[0]]),
				$headerTo = renderColumnHeader('to', revisionData[values[1]]);
				$revisionsHeaders.empty().append($headerFrom).append($headerTo);
		};
		var $slider = $('.wp-slider');
		$slider.on('slide change', function(event, ui) {
			renderColumnHeaders(ui.value, ui.values);
		});
		renderColumnHeaders($slider.slider('value'), $slider.slider('values'));
	});
})(jQuery);
