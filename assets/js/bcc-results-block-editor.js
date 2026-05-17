(function (blocks, element, blockEditor) {
  const el       = element.createElement;
  const useBlockProps = blockEditor.useBlockProps;

  blocks.registerBlockType('bcc-search/results', {
    title:       'BCC Search Results',
    description: 'Displays search results on the search results page. Add this block to your /search/ page.',
    category:    'bcc-search',
    icon:        'search',
    supports:    { html: false },

    edit: function () {
      const blockProps = useBlockProps({
        style: {
          padding: '24px',
          border: '2px dashed #16b5e6',
          borderRadius: '10px',
          textAlign: 'center',
          color: '#16b5e6',
          fontWeight: 600,
          fontSize: '0.9rem',
        },
      });
      return el(
        'div',
        blockProps,
        el('span', { style: { marginRight: '8px' } }, '🔍'),
        'BCC Search Results — results will appear here on the frontend when a visitor searches.'
      );
    },

    save: function () {
      return null; // server-side render via render_callback
    },
  });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor);