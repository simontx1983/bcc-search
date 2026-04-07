/**
 * BCC Search – Gutenberg Block Editor Registration
 *
 * Dynamic block with server-side rendering.
 * Shows a <ServerSideRender /> preview with InspectorControls.
 */
(function (wp) {
    var registerBlockType  = wp.blocks.registerBlockType;
    var createElement      = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var PanelBody          = wp.components.PanelBody;
    var TextControl        = wp.components.TextControl;
    var ToggleControl      = wp.components.ToggleControl;
    var ServerSideRender   = wp.serverSideRender;
    var useBlockProps      = wp.blockEditor.useBlockProps;

    registerBlockType('bcc-search/search-bar', {
        edit: function (props) {
            var attrs      = props.attributes;
            var blockProps = useBlockProps();

            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Placeholder text',
                            help: 'Text shown inside the search input when empty.',
                            value: attrs.placeholder || '',
                            onChange: function (v) { props.setAttributes({ placeholder: v }); },
                        }),
                        createElement(ToggleControl, {
                            label: 'Show type filter chips',
                            checked: attrs.showType,
                            onChange: function (v) { props.setAttributes({ showType: v }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-search/search-bar',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

})(window.wp);
