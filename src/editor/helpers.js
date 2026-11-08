/**
 * Pure helpers for the Findable editor sidebar. Unit-tested in helpers.test.js.
 */

export function band( score ) {
	if ( score >= 80 ) {
		return 'good';
	}
	return score >= 50 ? 'needs_work' : 'poor';
}

import { __, _n, sprintf } from '@wordpress/i18n';

export function bandLabel( score ) {
	if ( score >= 80 ) {
		return __( 'Good', 'findable' );
	}
	return score >= 50
		? __( 'Needs work', 'findable' )
		: __( 'Poor', 'findable' );
}

/**
 * Filter checks to a view. Failing is pass === false and applicable !== false.
 *
 * @param {Array}  checks Check rows { id, f, label, s }.
 * @param {string} filter failing|passing|na|all.
 */
export function filterChecks( checks, filter ) {
	if ( ! Array.isArray( checks ) ) {
		return [];
	}
	switch ( filter ) {
		case 'failing':
			return checks.filter( ( c ) => c.s === 'fail' );
		case 'passing':
			return checks.filter( ( c ) => c.s === 'pass' );
		case 'na':
			return checks.filter( ( c ) => c.s === 'na' );
		default:
			return checks;
	}
}

export function counts( checks ) {
	return {
		failing: filterChecks( checks, 'failing' ).length,
		passing: filterChecks( checks, 'passing' ).length,
		na: filterChecks( checks, 'na' ).length,
	};
}

export function byFamily( checks ) {
	const families = { seo: [], aeo: [], geo: [] };
	( checks || [] ).forEach( ( c ) => {
		if ( families[ c.f ] ) {
			families[ c.f ].push( c );
		}
	} );
	return families;
}

export function formatAge( seconds ) {
	if ( seconds < 60 ) {
		return __( 'just now', 'findable' );
	}
	if ( seconds < 3600 ) {
		const minutes = Math.round( seconds / 60 );
		return sprintf(
			/* translators: %s: minutes since scan. */
			_n( '%s minute ago', '%s minutes ago', minutes, 'findable' ),
			minutes
		);
	}
	if ( seconds < 86400 ) {
		const hours = Math.round( seconds / 3600 );
		return sprintf(
			/* translators: %s: hours since scan. */
			_n( '%s hour ago', '%s hours ago', hours, 'findable' ),
			hours
		);
	}
	const days = Math.round( seconds / 86400 );
	return sprintf(
		/* translators: %s: days since scan. */
		_n( '%s day ago', '%s days ago', days, 'findable' ),
		days
	);
}
