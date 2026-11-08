<?php
/**
 * Bundled English fix advice, keyed by check id.
 *
 * Source: https://findable.page web UI (findable-page/web/index.html fixAdvice),
 * MIT licensed, GPL-compatible. Regenerate with `php bin/sync-fix-advice.php`.
 *
 * @package findable-wp
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title_exists'           => 'Add a distinct title tag inside head that names the page\'s purpose.',
	'title_length'           => 'Keep the title between 30 and 60 characters so search engines do not truncate it.',
	'desc_exists'            => 'Add a meta description with a short summary of the page.',
	'desc_length'            => 'Aim for 120 to 160 characters in the meta description.',
	'h1_exists'              => 'Use exactly one h1 that states the primary topic.',
	'heading_hierarchy'      => 'Nest headings in order (h1, h2, h3) without skipping levels.',
	'word_count'             => 'Expand thin content to at least 300 words to show topical depth.',
	'canonical'              => 'Add a canonical link tag to prevent duplicate content.',
	'og_image'               => 'Add an og:image meta tag with an absolute image URL.',
	'og_url'                 => 'Add an og:url meta tag with the page\'s canonical address.',
	'internal_links'         => 'Link to at least two other pages on your site with descriptive anchor text.',
	'external_links'         => 'Link out to at least one trustworthy source that backs up a claim.',
	'img_alt'                => 'Give every image a descriptive alt attribute.',
	'img_dimensions'         => 'Set width and height on images to prevent layout shift.',
	'jsonld'                 => 'Add a JSON-LD block that describes the page, such as Organization or SoftwareApplication.',
	'aeo_structured_data'    => 'Add JSON-LD structured data so AI systems can read the page\'s facts.',
	'aeo_faq'                => 'Add FAQPage structured data with short question and answer pairs.',
	'aeo_direct_answers'     => 'Put a 30 to 80 word direct answer right under each question heading.',
	'aeo_question_headings'  => 'Phrase some headings as the questions people ask, such as What is...?',
	'aeo_definition_lists'   => 'Use dl, dt and dd to define key terms.',
	'aeo_numbered_steps'     => 'Use an ol for step-by-step instructions.',
	'aeo_collapsible'        => 'Put FAQs in details and summary elements.',
	'aeo_summary'            => 'Add a Key takeaways section that recaps the page in a few lines.',
	'geo_statistics_density' => 'Include concrete numbers, measurements and benchmarks you can stand behind.',
	'geo_expert_quotes'      => 'Use blockquote with an attributed source to show expertise.',
	'geo_citations'          => 'Link to reputable external sources that back up your claims.',
	'geo_definitions'        => 'Define key terms in plain sentences such as X is a...',
	'geo_author_schema'      => 'Add Person schema for the author, or an author field in Article schema.',
	'geo_org_schema'         => 'Declare an Organization in JSON-LD with your name, URL and logo.',
	'geo_knowledge_graph'    => 'Add sameAs links to Wikidata, LinkedIn or Wikipedia in your Organization schema.',
	'aeo_tables'             => 'Add an HTML table that compares options or lists data.',
	'geo_data_viz'           => 'Add an HTML table that compares options or lists data.',
	'geo_passage_citability' => 'Write self-contained 40 to 120 word paragraphs that answer one question on their own.',
	'geo_trust'              => 'Link to About and Contact pages so readers see who stands behind the site.',
	'geo_time_element'       => 'Mark up published and updated dates with the time element.',
);
