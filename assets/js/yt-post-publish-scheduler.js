/**
 * YT Post Publish Scheduler - JavaScript
 *
 * @format
 * @package YT_Post_Publish_Scheduler
 * @version 1.0.0
 */

(function ($) {
	"use strict";

	/**
	 * Post Publish Scheduler Handler
	 */
	var PostPublishScheduler = {
		/**
		 * Initialize the plugin.
		 */
		init: function () {
			this.bindEvents();
			this.validateDates();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function () {
			var self = this;

			// Clear schedule button.
			$(document).on("click", "#yt-pps-clear-schedule", function (e) {
				e.preventDefault();
				self.clearSchedule();
			});

			// Date change validation.
			$(document).on("change", "#yt-pps-unpublish-date, #yt-pps-republish-date", function () {
				self.validateDates();
			});

			// Form submission validation.
			$("#post").on("submit", function (e) {
				if (!self.validateBeforeSubmit()) {
					e.preventDefault();
					return false;
				}
			});

			// Real-time validation.
			$(document).on("input", "#yt-pps-unpublish-date, #yt-pps-republish-date", function () {
				self.clearValidationMessages();
			});
		},

		/**
		 * Clear schedule via AJAX.
		 */
		clearSchedule: function () {
			if (!confirm(ytPpsData.strings.confirmClear || "Are you sure?")) {
				return;
			}

			var self = this;
			var postId = $("#post_ID").val();
			var $button = $("#yt-pps-clear-schedule");

			// Show loading state.
			$button.prop("disabled", true).text("Clearing...");

			$.ajax({
				url: ytPpsData.ajaxUrl,
				type: "POST",
				data: {
					action: "yt_pps_clear_schedule",
					nonce: ytPpsData.nonce,
					post_id: postId
				},
				success: function (response) {
					if (response.success) {
						// Clear form fields.
						$("#yt-pps-unpublish-date").val("");
						$("#yt-pps-republish-date").val("");

						// Remove schedule info.
						$(".yt-pps-schedule-info").fadeOut(300, function () {
							$(this).remove();
						});

						// Hide clear button.
						$button.fadeOut(300);

						// Show success message.
						self.showMessage(ytPpsData.strings.scheduleCleared, "success");
					} else {
						self.showMessage(response.data.message || ytPpsData.strings.error, "error");
						$button.prop("disabled", false).text("Clear Schedule");
					}
				},
				error: function () {
					self.showMessage(ytPpsData.strings.error, "error");
					$button.prop("disabled", false).text("Clear Schedule");
				}
			});
		},

		/**
		 * Validate dates.
		 *
		 * @return {boolean} Whether dates are valid.
		 */
		validateDates: function () {
			var unpublishDate = $("#yt-pps-unpublish-date").val();
			var republishDate = $("#yt-pps-republish-date").val();

			// Clear previous messages.
			this.clearValidationMessages();

			// If both are empty, no validation needed.
			if (!unpublishDate && !republishDate) {
				return true;
			}

			var now = new Date();
			var valid = true;

			// Validate unpublish date.
			if (unpublishDate) {
				var unpublishDateTime = new Date(unpublishDate);

				if (!ytPpsData.allowPastDates && unpublishDateTime < now) {
					this.showFieldError("#yt-pps-unpublish-date", ytPpsData.strings.pastDate);
					valid = false;
				}
			}

			// Validate republish date.
			if (republishDate) {
				var republishDateTime = new Date(republishDate);

				if (!ytPpsData.allowPastDates && republishDateTime < now) {
					this.showFieldError("#yt-pps-republish-date", ytPpsData.strings.pastDate);
					valid = false;
				}
			}

			// Validate date order.
			if (unpublishDate && republishDate) {
				var unpublishDateTime = new Date(unpublishDate);
				var republishDateTime = new Date(republishDate);

				if (republishDateTime <= unpublishDateTime) {
					this.showFieldError("#yt-pps-republish-date", ytPpsData.strings.unpublishAfter);
					valid = false;
				}
			}

			return valid;
		},

		/**
		 * Validate before form submission.
		 *
		 * @return {boolean} Whether form is valid.
		 */
		validateBeforeSubmit: function () {
			return this.validateDates();
		},

		/**
		 * Show field error message.
		 *
		 * @param {string} field   Field selector.
		 * @param {string} message Error message.
		 */
		showFieldError: function (field, message) {
			var $field = $(field);
			var $error = $("<span>", {
				class: "yt-pps-error",
				text: message
			});

			$field.addClass("yt-pps-error-field").after($error);

			// Add visual indicator.
			$field.css("border-color", "#d63638");
		},

		/**
		 * Clear validation messages.
		 */
		clearValidationMessages: function () {
			$(".yt-pps-error").remove();
			$("#yt-pps-unpublish-date, #yt-pps-republish-date").removeClass("yt-pps-error-field").css("border-color", "");
		},

		/**
		 * Show notification message.
		 *
		 * @param {string} message Message text.
		 * @param {string} type    Message type (success, error, warning).
		 */
		showMessage: function (message, type) {
			type = type || "info";

			var $message = $("<div>", {
				class: "notice notice-" + type + " is-dismissible",
				html: "<p>" + message + "</p>"
			});

			// Insert after page title.
			$(".wrap h1").first().after($message);

			// Auto-dismiss after 5 seconds.
			setTimeout(function () {
				$message.fadeOut(300, function () {
					$(this).remove();
				});
			}, 5000);

			// Manual dismiss.
			$message.find(".notice-dismiss").on("click", function () {
				$message.fadeOut(300, function () {
					$(this).remove();
				});
			});
		},

		/**
		 * Format date for display.
		 *
		 * @param {string} dateString Date string.
		 * @return {string} Formatted date.
		 */
		formatDate: function (dateString) {
			if (!dateString) {
				return "";
			}

			var date = new Date(dateString);
			return date.toLocaleDateString() + " " + date.toLocaleTimeString();
		},

		/**
		 * Get time until date.
		 *
		 * @param {string} dateString Date string.
		 * @return {string} Human-readable time difference.
		 */
		getTimeUntil: function (dateString) {
			if (!dateString) {
				return "";
			}

			var now = new Date();
			var targetDate = new Date(dateString);
			var diff = targetDate - now;

			if (diff < 0) {
				return "Past";
			}

			var days = Math.floor(diff / (1000 * 60 * 60 * 24));
			var hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
			var minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

			if (days > 0) {
				return days + " day" + (days > 1 ? "s" : "");
			} else if (hours > 0) {
				return hours + " hour" + (hours > 1 ? "s" : "");
			} else {
				return minutes + " minute" + (minutes > 1 ? "s" : "");
			}
		},

		/**
		 * Add countdown timer.
		 */
		addCountdownTimer: function () {
			var self = this;
			var $unpublishDate = $("#yt-pps-unpublish-date");
			var $republishDate = $("#yt-pps-republish-date");

			if ($unpublishDate.length && $unpublishDate.val()) {
				var timeUntil = this.getTimeUntil($unpublishDate.val());
				if (timeUntil) {
					var $countdown = $("<p>", {
						class: "yt-pps-countdown",
						html: '<span class="dashicons dashicons-clock"></span> ' + timeUntil + " until unpublish"
					});
					$unpublishDate.after($countdown);
				}
			}

			if ($republishDate.length && $republishDate.val()) {
				var timeUntil = this.getTimeUntil($republishDate.val());
				if (timeUntil) {
					var $countdown = $("<p>", {
						class: "yt-pps-countdown",
						html: '<span class="dashicons dashicons-clock"></span> ' + timeUntil + " until republish"
					});
					$republishDate.after($countdown);
				}
			}

			// Update countdown every minute.
			setInterval(function () {
				$(".yt-pps-countdown").remove();
				self.addCountdownTimer();
			}, 60000);
		},

		/**
		 * Add quick date presets.
		 */
		addQuickPresets: function () {
			var self = this;

			var presets = {
				"1hour": { label: "1 Hour", offset: 1 },
				"1day": { label: "1 Day", offset: 24 },
				"1week": { label: "1 Week", offset: 168 },
				"1month": { label: "1 Month", offset: 720 }
			};

			var $presetsContainer = $("<div>", {
				class: "yt-pps-presets",
				html: "<strong>Quick Presets:</strong> "
			});

			$.each(presets, function (key, preset) {
				var $button = $("<button>", {
					type: "button",
					class: "button button-small",
					text: preset.label,
					"data-offset": preset.offset
				});

				$button.on("click", function (e) {
					e.preventDefault();
					var offset = parseInt($(this).data("offset"));
					var date = new Date();
					date.setHours(date.getHours() + offset);

					var formattedDate = self.formatDateForInput(date);
					$("#yt-pps-unpublish-date").val(formattedDate).trigger("change");
				});

				$presetsContainer.append($button).append(" ");
			});

			$("#yt-pps-unpublish-date").after($presetsContainer);
		},

		/**
		 * Format date for input field.
		 *
		 * @param {Date} date Date object.
		 * @return {string} Formatted date string.
		 */
		formatDateForInput: function (date) {
			var year = date.getFullYear();
			var month = String(date.getMonth() + 1).padStart(2, "0");
			var day = String(date.getDate()).padStart(2, "0");
			var hours = String(date.getHours()).padStart(2, "0");
			var minutes = String(date.getMinutes()).padStart(2, "0");

			return year + "-" + month + "-" + day + "T" + hours + ":" + minutes;
		},

		/**
		 * Add timezone indicator.
		 */
		addTimezoneIndicator: function () {
			var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
			var $indicator = $("<p>", {
				class: "description",
				text: "Timezone: " + timezone
			});

			$(".yt-pps-meta-box").append($indicator);
		},

		/**
		 * Highlight active schedules.
		 */
		highlightActiveSchedules: function () {
			$(".yt-pps-schedule-info p").each(function () {
				var $this = $(this);
				var dateText = $this.text();

				// Extract date from text.
				var dateMatch = dateText.match(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/);
				if (dateMatch) {
					var scheduledDate = new Date(dateMatch[0]);
					var now = new Date();

					// Highlight if upcoming.
					if (scheduledDate > now) {
						$this.css("color", "#2271b1").css("font-weight", "bold");
					} else {
						$this.css("color", "#646970");
					}
				}
			});
		},

		/**
		 * Add visual timeline.
		 */
		addVisualTimeline: function () {
			var unpublishDate = $("#yt-pps-unpublish-date").val();
			var republishDate = $("#yt-pps-republish-date").val();

			if (!unpublishDate && !republishDate) {
				return;
			}

			var $timeline = $("<div>", { class: "yt-pps-timeline" });

			if (unpublishDate) {
				var $item = $("<div>", {
					class: "yt-pps-timeline-item unpublish",
					html: "<strong>Unpublish:</strong> " + this.formatDate(unpublishDate)
				});
				$timeline.append($item);
			}

			if (republishDate) {
				var $item = $("<div>", {
					class: "yt-pps-timeline-item republish",
					html: "<strong>Republish:</strong> " + this.formatDate(republishDate)
				});
				$timeline.append($item);
			}

			$(".yt-pps-meta-box").append($timeline);
		},

		/**
		 * Show warning for conflicting dates.
		 */
		checkDateConflicts: function () {
			var unpublishDate = $("#yt-pps-unpublish-date").val();
			var republishDate = $("#yt-pps-republish-date").val();

			// Check if republish is too soon after unpublish.
			if (unpublishDate && republishDate) {
				var unpublishDateTime = new Date(unpublishDate);
				var republishDateTime = new Date(republishDate);
				var diff = (republishDateTime - unpublishDateTime) / (1000 * 60); // Minutes

				if (diff < 60) {
					var $warning = $("<span>", {
						class: "yt-pps-warning",
						text: "Warning: Republish is scheduled very soon after unpublish (" + Math.round(diff) + " minutes)"
					});
					$("#yt-pps-republish-date").after($warning);
				}
			}
		},

		/**
		 * Add keyboard shortcuts.
		 */
		addKeyboardShortcuts: function () {
			$(document).on("keydown", function (e) {
				// Only on post edit screen.
				if (!$(".yt-pps-meta-box").length) {
					return;
				}

				// Ctrl/Cmd + Shift + U: Focus unpublish date.
				if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === "u") {
					e.preventDefault();
					$("#yt-pps-unpublish-date").focus();
				}

				// Ctrl/Cmd + Shift + R: Focus republish date.
				if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === "r") {
					e.preventDefault();
					$("#yt-pps-republish-date").focus();
				}

				// Ctrl/Cmd + Shift + C: Clear schedule.
				if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === "c") {
					e.preventDefault();
					$("#yt-pps-clear-schedule").click();
				}
			});
		},

		/**
		 * Auto-save detection.
		 */
		handleAutoSave: function () {
			// Prevent auto-save from clearing custom fields.
			$(document).on("heartbeat-send", function (event, data) {
				data["yt_pps_unpublish_date"] = $("#yt-pps-unpublish-date").val();
				data["yt_pps_republish_date"] = $("#yt-pps-republish-date").val();
			});
		}
	};

	/**
	 * Initialize when DOM is ready.
	 */
	$(document).ready(function () {
		// Check if we're on post edit screen with meta box.
		if ($(".yt-pps-meta-box").length > 0) {
			PostPublishScheduler.init();
			PostPublishScheduler.addTimezoneIndicator();
			PostPublishScheduler.highlightActiveSchedules();
			PostPublishScheduler.addKeyboardShortcuts();
			PostPublishScheduler.handleAutoSave();
			// PostPublishScheduler.addQuickPresets(); // Optional
			// PostPublishScheduler.addCountdownTimer(); // Optional
		}
	});

	// Expose to global scope for external use.
	window.PostPublishScheduler = PostPublishScheduler;
})(jQuery);
