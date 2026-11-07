/**
 * Findable block editor sidebar.
 *
 * All API-originated text (check labels, advice) renders as React text nodes
 * only; no dangerouslySetInnerHTML anywhere in this file or the build.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import {
	PanelBody,
	PanelRow,
	Button,
	TextControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	band,
	bandLabel,
	filterChecks,
	counts,
	byFamily,
	formatAge,
} from './helpers';

function FamilyScore( { name, family } ) {
	if ( ! family ) {
		return null;
	}
	return (
		<div className="findable-family-score">
			{ name }:{ ' ' }
			{ sprintf(
				/* translators: 1: family score, 2: passed checks, 3: total checks */
				__( '%1$s (%2$s of %3$s passed)', 'findable' ),
				family.score,
				family.passed,
				family.total
			) }
		</div>
	);
}

function ChecksList( { checks, advice } ) {
	const families = byFamily( checks );
	return [ 'seo', 'aeo', 'geo' ].map( ( key ) =>
		families[ key ].length ? (
			<div key={ key } className="findable-family-group">
				<h4>{ key.toUpperCase() }</h4>
				<ul className="findable-checks">
					{ families[ key ].map( ( check ) => (
						<li
							key={ key + check.id }
							className={ 'findable-check-' + check.s }
						>
							<span className="findable-check-label">
								{ check.label }
							</span>
							{ check.s === 'fail' &&
							advice &&
							advice[ check.id ] ? (
								<span className="findable-advice">
									{ advice[ check.id ] }
								</span>
							) : null }
						</li>
					) ) }
				</ul>
			</div>
		) : null
	);
}

function FindableSidebar() {
	const [ payload, setPayload ] = useState( null );
	const [ filter, setFilter ] = useState( 'failing' );
	const [ keyphraseDraft, setKeyphraseDraft ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const { createNotice } = useDispatch( 'core/notices' );

	const editor = useSelect( ( select ) => {
		const core = select( 'core/editor' );
		return {
			postId: core.getCurrentPostId(),
			isDirty: core.isEditedPostDirty(),
			isPublished: core.isCurrentPostPublished(),
			postType: core.getCurrentPostType(),
		};
	} );

	const load = useCallback( async () => {
		try {
			const data = await apiFetch( {
				path: `/findable/v1/posts/${ editor.postId }/result`,
			} );
			setPayload( data );
			if ( null === keyphraseDraft ) {
				setKeyphraseDraft( data.keyphrase || '' );
			}
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error.message || __( 'Request failed.', 'findable' ),
			} );
		}
	}, [ editor.postId, keyphraseDraft ] );

	useEffect( () => {
		if ( editor.postId ) {
			load();
		}
	}, [ editor.postId, load ] );

	const scanNow = useCallback( async () => {
		setBusy( true );
		setNotice( null );
		try {
			const data = await apiFetch( {
				path: `/findable/v1/posts/${ editor.postId }/scan`,
				method: 'POST',
				data: { keyphrase: keyphraseDraft || '' },
			} );
			setPayload( data );
			if ( data.error ) {
				setNotice( { status: 'error', message: data.error.message } );
			} else {
				createNotice(
					'success',
					__( 'Findable scan complete', 'findable' ),
					{
						type: 'snackbar',
					}
				);
			}
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message: error.message || __( 'Request failed.', 'findable' ),
			} );
		} finally {
			setBusy( false );
		}
	}, [ editor.postId, keyphraseDraft, createNotice ] );

	if ( ! editor.postId ) {
		return null;
	}

	const result = payload ? payload.result : null;
	const c = result ? counts( result.checks || [] ) : null;

	return (
		<>
			<PluginSidebarMoreMenuItem target="findable-sidebar">
				{ __( 'Findable', 'findable' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name="findable-sidebar"
				title={ __( 'Findable', 'findable' ) }
			>
				<div className="findable-sidebar-body">
					{ ! editor.isPublished ? (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Publish this post first. Findable scans the live URL.',
								'findable'
							) }
						</Notice>
					) : null }
					{ editor.isDirty ? (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Save or update the post to score your latest edits.',
								'findable'
							) }
						</Notice>
					) : null }
					{ notice ? (
						<Notice
							status={ notice.status }
							isDismissible={ false }
						>
							{ notice.message }
						</Notice>
					) : null }
					{ payload && payload.error && ! notice ? (
						<Notice status="error" isDismissible={ false }>
							{ payload.error.message }
						</Notice>
					) : null }

					{ result ? (
						<>
							<PanelBody opened>
								<PanelRow>
									<div className="findable-overall">
										<span
											className={
												'findable-score findable-band-' +
												band( result.overall ).replace(
													'_',
													'-'
												)
											}
										>
											{ sprintf(
												/* translators: 1: score, 2: band label */
												__( '%1$s %2$s', 'findable' ),
												result.overall,
												bandLabel( result.overall )
											) }
										</span>
									</div>
								</PanelRow>
								<PanelRow>
									<div className="findable-families">
										<FamilyScore
											name="SEO"
											family={ result.families.seo }
										/>
										<FamilyScore
											name="AEO"
											family={ result.families.aeo }
										/>
										<FamilyScore
											name="GEO"
											family={ result.families.geo }
										/>
									</div>
								</PanelRow>
								<PanelRow>
									<span className="findable-freshness">
										{ payload.fresh
											? sprintf(
													/* translators: %s: human-readable age */
													__(
														'Scanned %s',
														'findable'
													),
													formatAge(
														payload.age_seconds
													)
											  )
											: sprintf(
													/* translators: %s: human-readable age */
													__(
														'Stale: post changed since last scan (scanned %s)',
														'findable'
													),
													formatAge(
														payload.age_seconds
													)
											  ) }
									</span>
								</PanelRow>
								<PanelRow>
									<a
										href={ payload.report_url }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ __(
											'Full report on findable.page',
											'findable'
										) }
									</a>
								</PanelRow>
							</PanelBody>

							{ result.legacy ? (
								<p>
									{ __(
										'Imported from findable 0.2. Scan again to see the full breakdown.',
										'findable'
									) }
								</p>
							) : (
								<PanelBody
									title={ __( 'Checks', 'findable' ) }
									initialOpen
								>
									<div
										className="findable-filters"
										role="tablist"
									>
										{ [
											[
												'failing',
												sprintf(
													/* translators: %s: number of checks */
													__(
														'Failing (%s)',
														'findable'
													),
													c.failing
												),
											],
											[
												'passing',
												sprintf(
													/* translators: %s: number of checks */
													__(
														'Passing (%s)',
														'findable'
													),
													c.passing
												),
											],
											[
												'na',
												sprintf(
													/* translators: %s: number of checks */
													__(
														'Not applicable (%s)',
														'findable'
													),
													c.na
												),
											],
										].map( ( [ key, label ] ) => (
											<Button
												key={ key }
												isPrimary={ filter === key }
												isSecondary={ filter !== key }
												aria-selected={ filter === key }
												onClick={ () =>
													setFilter( key )
												}
											>
												{ label }
											</Button>
										) ) }
									</div>
									<ChecksList
										checks={ filterChecks(
											result.checks,
											filter
										) }
										advice={ payload.advice }
									/>
								</PanelBody>
							) }
						</>
					) : (
						<p>{ __( 'Not scanned yet.', 'findable' ) }</p>
					) }

					<TextControl
						label={ __( 'Keyphrase (optional)', 'findable' ) }
						value={ keyphraseDraft || '' }
						maxLength={ 100 }
						onChange={ setKeyphraseDraft }
						help={ __(
							'Sent with the scan so the API can score keyphrase checks.',
							'findable'
						) }
					/>
					<Button
						variant="primary"
						onClick={ scanNow }
						disabled={ busy || ! payload || ! payload.scannable }
					>
						{ busy
							? __( 'Scanning…', 'findable' )
							: __( 'Scan now', 'findable' ) }
					</Button>
					{ busy ? <Spinner /> : null }
				</div>
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'findable', { icon: 'search', render: FindableSidebar } );
