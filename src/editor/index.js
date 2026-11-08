/**
 * Findable block editor entry: registers the sidebar plugin.
 */
import { registerPlugin } from '@wordpress/plugins';
import { FindableSidebar } from './sidebar';

registerPlugin( 'findable', { icon: 'search', render: FindableSidebar } );
