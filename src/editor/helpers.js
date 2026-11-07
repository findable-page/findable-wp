/**
 * Pure helpers for the Findable editor sidebar. Unit-tested in helpers.test.js.
 */

export function band( score ) {
	if ( score >= 80 ) {
		return 'good';
	}
	return score >= 50 ? 'needs_work' : 'poor';
}

export function bandLabel( score ) {
	if ( score >= 80 ) {
		return 'Good';
	}
	return score >= 50 ? 'Needs work' : 'Poor';
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
		return 'just now';
	}
	if ( seconds < 3600 ) {
		const minutes = Math.round( seconds / 60 );
		return minutes + ( minutes === 1 ? ' minute ago' : ' minutes ago' );
	}
	if ( seconds < 86400 ) {
		const hours = Math.round( seconds / 3600 );
		return hours + ( hours === 1 ? ' hour ago' : ' hours ago' );
	}
	const days = Math.round( seconds / 86400 );
	return days + ( days === 1 ? ' day ago' : ' days ago' );
}
