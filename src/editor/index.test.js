/**
 * The entry file only registers the plugin with the editor.
 */
const mockRegister = jest.fn();

jest.mock( '@wordpress/plugins', () => ( { registerPlugin: mockRegister } ), {
	virtual: true,
} );
jest.mock( './sidebar', () => ( {
	FindableSidebar: function FindableSidebar() {},
} ) );

describe( 'editor entry', () => {
	it( 'registers the findable plugin with the sidebar renderer', () => {
		require( './index' );
		expect( mockRegister ).toHaveBeenCalledTimes( 1 );
		const [ name, settings ] = mockRegister.mock.calls[ 0 ];
		expect( name ).toBe( 'findable' );
		expect( settings.icon ).toBe( 'search' );
		expect( settings.render.name ).toBe( 'FindableSidebar' );
	} );
} );
