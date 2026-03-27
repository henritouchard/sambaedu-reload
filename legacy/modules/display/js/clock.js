
jQuery(document).ready(function ($) {

	wpdsClockUpdate();
	setInterval( function() {
		wpdsClockUpdate();
	}, 1000);
});

function wpdsClockUpdate() {

	jQuery('.clock').each(function(){
		var now = moment();

		var locale = "fr-ca"; // jQuery(this).attr('data-locale');
		if (locale) {
			now = now.locale(locale);
		}

		var timezone = "fr-ca"; //jQuery(this).attr('data-timezone');
		if (timezone) {
			now = now.tz(timezone);
		}

		jQuery(this).find(".clock-hours").html(now.format('HH'));
		jQuery(this).find(".clock-minutes").html(now.format('mm'));
		jQuery(this).find('.clock-date').html(now.format('dddd LL'));
	});
}
