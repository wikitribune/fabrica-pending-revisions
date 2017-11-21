(function($) {
	$(function() {
		var revisionData = _wpRevisionsSettings.revisionData,
			acceptedIndex = revisionData.length - 1,
			revisionFrame = wp.revisions.view.frame,
			revisionModels = revisionFrame.model.revisions.models,
			controlViews = revisionFrame.views.first('.revisions-control-frame').views.get(''),
			sliderView;

		// Get accepted revision index in revisions data array
		for (var i = acceptedIndex; i >= 0; i--) {
			revision = revisionData[i];
			if (revision.current) {
				if (revision.pending) {

					// When the last revision is an autosave WP sets the current property in the last non-autosave revision in the data sent to JS (after the `wp_prepare_revision_for_js` hook where we set the correct current revision)
					revision.current = false;
					if (revisionModels[i]) {
						revisionModels[i].attributes.current = false;
					}
					continue;
				}
				acceptedIndex = i;
				break;
			}
		}

		// Always show current revision tooltip when not hovering slider
		var $slider = $('.wp-slider');
		for (var i = 0; i < controlViews.length; i++) {

			// Get Slider's Backbone view
			if (controlViews[i].el != $slider[0]) { continue; }
			sliderView = controlViews[i];
			break;
		}

		var showCurrentRevisionTooltip = function(model) {
			model.set({
				hovering: true,
				hoveredRevision: revisionModels[acceptedIndex]
			});
		};
		sliderView.model.on('change:hovering', function(model, value) {
			if (value) { return; } // Hovering
			showCurrentRevisionTooltip(model);
		});

		// Mark the accepted revision visually
		var renderMarks = function(acceptedIndex) {
			if (acceptedIndex >= revisionData.length - 1) { return; }

			$('.fcr-current-revision-tickmark').remove();
			$('.revisions-tickmarks div').css({ borderLeft: '' });
			$('.revisions-tickmarks div:nth-child(' + (acceptedIndex + 1) + ')').css({
				borderLeft: '3px solid #46b450'
			});
			var acceptedPosition = acceptedIndex / (revisionData.length - 1) * 100,
				$pendingChangesTickmarks = $('<span>', { class: 'fcr-current-revision-tickmark' });
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

			showCurrentRevisionTooltip(sliderView.model);
		}
		renderMarks(acceptedIndex);

		// Change columns' header cards
		var $revisionsHeaders = $('<div>', {class: 'fpr-revisions-headers'});
		$('.revisions-controls').append($revisionsHeaders);
		var renderColumnHeader = function(side, revisionIndex) {

			// Render header fields
			var revision = revisionData[revisionIndex], $header = $('<div>', {
				class: 'fpr-revisions-headers__' + side,
				html: [

					// Title
					$('<div>', {
						class: 'fpr-revisions-headers__title',
						html: [
							$('<span>', {class: 'fpr-revisions-headers__type'}),
							$('<span>', {
								class: 'fpr-revisions-headers__id',
								text: 'Revision ID ' + revision.id
							})
						]
					}),

					// Submission date/time
					$('<div>', {
						class: 'fpr-revisions-headers__date',
						html: [
							$('<span>', {text: 'submitted '}),
							$('<span>', {
								class: 'fpr-revisions-headers__date-ago',
								text: revision.timeAgo + ' '
							}),
							$('<span>', {
								class: 'fpr-revisions-headers__date-long',
								text: revision.date
							})
						]
					}),

					// Author
					$('<div>', {
						class: 'fpr-revisions-headers__author',
						html: [
							$('<span>', {text: 'by '}),
							$('<span>', {
								class: 'fpr-revisions-headers__author-name',
								text: revision.author.name + ' '
							}),
							$('<span>', {
								class: 'fpr-revisions-headers__author-role',
								text: '(' + revision.author.role + ')'
							})
						]
					}),

					// Note
					((!revision.note && !revision.sourceRevisionID) ? null : $('<div>', {
						class: 'fpr-revisions-headers__note',
						html: [
							((!revision.note) ? null : $('<span>', {
								class: 'fpr-revisions-headers__note-caption',
								text: 'Note: '
							})),
							$('<span>', {
								html: [
									((!revision.note) ? null : $('<div>', {
										class: 'fpr-revisions-headers__note-text',
										html: revision.note + ' ' // Set on `html` to decode possible HTML entities
									})),
									((!revision.sourceRevisionID) ? null : $('<div>', {
										class: 'fpr-revisions-headers__based-on',
										html: [
											$('<span>', {text: 'based on Revision '}),
											$('<span>', {text: revision.sourceRevisionID})
										]
									}))
								]
							})
						]
					})),
				]
			});

			// Buttons
			$buttons = $('<div>', { class: 'fpr-revisions-buttons' });
			if (!revision.autosave) {
				if (revision.userCanAccept) {
					$buttons.append($('<input>', {
						type: 'button',
						value: 'Edit',
						class: 'button button-secondary fpr-revisions-buttons__button fpr-revisions-buttons__button--edit',
					}));
				}
				$buttons.append($('<input>', {
					type: 'button',
					value: revision.current ? 'View' : 'Preview',
					class: 'button button-secondary fpr-revisions-buttons__button fpr-revisions-buttons__button--preview',
				}));
				if (!revision.current && revision.userCanAccept) {
					$buttons.append( $('<input>', {
						type: 'button',
						value: 'Publish',
						class: 'button button-primary fpr-revisions-buttons__button fpr-revisions-buttons__button--publish',
					}));
				}
				if (revision.notice) {
					$buttons.append($('<span>', {
						class: 'fpr-revisions-headers__published-notice ' + (revision.notice.success ? 'fpr-revisions-headers__published-notice--success' : 'fpr-revisions-headers__published-notice--error'),
						text: revision.notice.message
					}));
				}
			} else if (revision.author.current) {
				$buttons.append($('<input>', {
					type: 'button',
					value: 'Retrieve',
					class: 'button button-primary fpr-revisions-buttons__button fpr-revisions-buttons__button--retrieve',
				}));
			}
			$header.append($buttons);
			revision.notice = false;

			// Set title class and text according to revision status
			$revisionType = $('.fpr-revisions-headers__type', $header);
			if (revision.current) {
				$revisionType.addClass('fpr-revisions-headers__type--current')
					.text('Current Published ');
			} else if (revision.pending && !revision.autosave) {
				$revisionType.addClass('fpr-revisions-headers__type--pending')
					.text('Pending ');
			} else if (revision.autosave) {
				$revisionType.addClass('fpr-revisions-headers__type--autosave')
					.text('Autosave ');
			}

			var $retrieveButton = $('.fpr-revisions-buttons__button--retrieve', $header),
				$editButton = $('.fpr-revisions-buttons__button--edit', $header),
				$previewButton = $('.fpr-revisions-buttons__button--preview', $header),
				$publishButton = $('.fpr-revisions-buttons__button--publish', $header);

			// Buttons actions
			$retrieveButton.click(function() { document.location = revision.urls.edit; });
			$editButton.click(function() { document.location = revision.urls.edit; });
			$previewButton.click(function() { window.open(revision.urls.preview, '_blank'); });
			$publishButton.click(function(event) {
				$publishButton.attr('disabled', true);
				$('html').addClass('fpr-util-wait');
				$('.fpr-revisions-headers__published-notice').remove();
				$.ajax({
					type: 'POST',
					url: revision.urls.ajax,
					context: this,
					data: {
						action: 'fpr-revision-publish',
						security: revision.nonce,
						data: {
							revision: revision.id,
						}
					}
				}).done(function(response) {
					$('html').removeClass('fpr-util-wait');
					if (response.success) {

						// Update revisions data and re-render tickmarks and headers
						for (var i = 0; i < revisionData.length; i++) {
							if (!revisionData[i]) { continue; }
							revisionData[i].pending = false;
							revisionData[i].current = false;
							if (i > revisionIndex) {
								revisionData[i].pending = true;
							} else if (i == revisionIndex) {
								revisionData[i].current = true;
								revisionData[i].notice = {
									success: true,
									message: 'Published'
								}
							}
							if (revisionModels[i]) {
								revisionModels[i].attributes.pending = revisionData[i].pending;
								revisionModels[i].attributes.current = revisionData[i].current;
							}
						}
						acceptedIndex = revisionIndex;
						renderMarks(acceptedIndex);
						renderColumnHeaders($slider.slider('value'), $slider.slider('values'));
					} else {
						$publishButton.after(
							$('<span>', {
								class: 'fpr-revisions-headers__published-notice fpr-revisions-headers__published-notice--error',
								text: 'Error publishing revision'
							})
						).attr('disabled', false);
					}
				});
			});

			return $header;
		};
		var renderColumnHeaders = function(value, values) {
			if (!values || values.length <= 0) {
				values = [value - 1, value];
			}
			if (values[0] == values[1] || values[0] < 0) { return; }

			var $headerFrom = renderColumnHeader('from', values[0]),
				$headerTo = renderColumnHeader('to', values[1]);
				$revisionsHeaders.empty().append($headerFrom).append($headerTo);
		};
		var $slider = $('.wp-slider');
		$slider.on('slide change', function(event, ui) {
			renderColumnHeaders(ui.value, ui.values);
		});
		renderColumnHeaders($slider.slider('value'), $slider.slider('values'));
	});
})(jQuery);
