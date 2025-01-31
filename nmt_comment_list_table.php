<?php

class NMT_comment_moderation_list extends WP_List_Table
{

	var $checkbox = true;
	var $pending_count = array();

	function __construct( $args = array() )
	{
		global $post_id;
		$post_id = isset( $_REQUEST['p'] ) ? absint( $_REQUEST['p'] ) : 0;

		if ( get_option('show_avatars') )
			add_filter( 'comment_author', 'floated_admin_avatar' );

		parent::__construct( array(
			'plural' => 'comments',
			'singular' => 'comment',
			'ajax' => true,
			'screen' => isset( $args['screen'] ) ? $args['screen'] : null,
		) );
	}

	function ajax_user_can() {
		return current_user_can('edit_posts');
	}

	function prepare_items() {
		global $post_id, $comment_status, $search, $comment_type;

		$comment_status = isset( $_REQUEST['comment_status'] ) ? $_REQUEST['comment_status'] : 'all';
		if ( !in_array( $comment_status, array( 'all', 'moderated', 'approved', 'spam', 'trash' ) ) )
			$comment_status = 'all';
		$comment_type = !empty( $_REQUEST['comment_type'] ) ? $_REQUEST['comment_type'] : '';
		$search = ( isset( $_REQUEST['s'] ) ) ? $_REQUEST['s'] : '';
		$post_type = ( isset( $_REQUEST['post_type'] ) ) ? sanitize_key( $_REQUEST['post_type'] ) : '';
		$user_id = ( isset( $_REQUEST['user_id'] ) ) ? $_REQUEST['user_id'] : '';
		$orderby = ( isset( $_REQUEST['orderby'] ) ) ? $_REQUEST['orderby'] : '';
		$order = ( isset( $_REQUEST['order'] ) ) ? $_REQUEST['order'] : '';

		$comments_per_page = 50; //$this->get_per_page( $comment_status );

		$doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;

		if ( isset( $_REQUEST['number'] ) ) {
			$number = (int) $_REQUEST['number'];
		}
		else {
			$number = $comments_per_page + min( 8, $comments_per_page ); // Grab a few extra
		}

		$page = $this->get_pagenum();

		if ( isset( $_REQUEST['start'] ) ) {
			$start = $_REQUEST['start'];
		} else {
			$start = ( $page - 1 ) * $comments_per_page;
		}

		if ( $doing_ajax && isset( $_REQUEST['offset'] ) ) {
			$start += $_REQUEST['offset'];
		}

		$status_map = array(
			'moderated' => 'hold',
			'approved' => 'approve',
			'all' => '',
		);

		$args = array(
			'status' => isset( $status_map[$comment_status] ) ? $status_map[$comment_status] : $comment_status,
			'search' => $search,
			'user_id' => $user_id,
			'offset' => $start,
			'number' => $number,
			'post_id' => $post_id,
			'type' => $comment_type,
			'orderby' => $orderby,
			'order' => $order,
			'post_type' => $post_type,
		);

		$_comments = $this->get_comments( $args );

		// ?? update_comment_cache( $_comments );

		$this->items = array_slice( $_comments, 0, $comments_per_page );
		$this->extra_items = array_slice( $_comments, $comments_per_page );

		$total_comments = $this->get_comments( array_merge( $args, array('count' => true, 'offset' => 0, 'number' => 0) ) );

		$_comment_post_ids = array();
		foreach ( $_comments as $_c ) {
			$_comment_post_ids[] = $_c->comment_post_ID;
		}

		$_comment_post_ids = array_unique( $_comment_post_ids );

		// we will do this for each row since we are in different blogs
		//$this->pending_count = get_pending_comments_num( $_comment_post_ids );

		$this->set_pagination_args( array(
			'total_items' => $total_comments,
			'per_page' => $comments_per_page,
		) );
	}

	function get_per_page( $comment_status = 'all' ) {
		$comments_per_page = $this->get_items_per_page( 'edit_comments_per_page' );
		/**
		 * Filter the number of comments listed per page in the comments list table.
		 *
		 * @since 2.6.0
		 *
		 * @param int    $comments_per_page The number of comments to list per page.
		 * @param string $comment_status    The comment status name. Default 'All'.
		 */
		$comments_per_page = apply_filters( 'comments_per_page', $comments_per_page, $comment_status );
		return $comments_per_page;
	}

	function no_items() {
		global $comment_status;

		if ( 'moderated' == $comment_status )
			_e( 'No comments awaiting moderation.' );
		else
			_e( 'No comments found.' );
	}

	function get_views() {
		global $post_id, $comment_status, $comment_type;

		$status_links = array();
		// hier!! -) found!!
		$num_comments = $this->count_comments();

		$stati = array(
				'all' => _nx_noop('All', 'All', 'comments'), // singular not used
				'moderated' => _n_noop('Pending <span class="count">(<span class="pending-count">%s</span>)</span>', 'Pending <span class="count">(<span class="pending-count">%s</span>)</span>'),
				'approved' => _n_noop('Approved', 'Approved'), // singular not used
				'spam' => _n_noop('Spam <span class="count">(<span class="spam-count">%s</span>)</span>', 'Spam <span class="count">(<span class="spam-count">%s</span>)</span>'),
				'trash' => _n_noop('Trash <span class="count">(<span class="trash-count">%s</span>)</span>', 'Trash <span class="count">(<span class="trash-count">%s</span>)</span>')
			);

		if ( !EMPTY_TRASH_DAYS )
			unset($stati['trash']);

		$link = 'edit-comments.php?page=nmt-sitewide-moderation';
		if ( !empty($comment_type) && 'all' != $comment_type )
			$link = add_query_arg( 'comment_type', $comment_type, $link );

		foreach ( $stati as $status => $label ) {
			$class = ( $status == $comment_status ) ? ' class="current"' : '';

			if ( !isset( $num_comments->$status ) )
				$num_comments->$status = 10;
			$link = add_query_arg( 'comment_status', $status, $link );
			if ( $post_id )
				$link = add_query_arg( 'p', absint( $post_id ), $link );

			$status_links[$status] = "<a href='$link'$class>" . sprintf(
				translate_nooped_plural( $label, $num_comments->$status ),
				number_format_i18n( $num_comments->$status )
			) . '</a>';
		}

		return $status_links;
	}

	function count_comments()
	{
		global $wpdb;
		$totals = (array) $wpdb->get_results("
			SELECT comment_approved, COUNT( * ) AS total
			FROM wp_all_comments
			WHERE comment_approved != 'post-trashed'
			GROUP BY comment_approved
			", ARRAY_A);
		$comment_count = array(
			"approved"  => 0,
			"moderated" => 0,
			"spam"      => 0,
			"trash"     => 0,
			"all"       => 0
		);

		foreach ( $totals as $row ) {
			switch ( $row['comment_approved'] ) {
				case 'trash':
					$comment_count['trash'] = $row['total'];
					break;
				case 'spam':
					$comment_count['spam'] = $row['total'];
					$comment_count["all"] += $row['total'];
					break;
				case 1:
					$comment_count['approved'] = $row['total'];
					$comment_count['all'] += $row['total'];
					break;
				case 0:
					$comment_count['moderated'] = $row['total'];
					$comment_count['all'] += $row['total'];
					break;
				default:
					break;
			}
		}

		// the original function returns an object, so do we.
		return (object) $comment_count;
	}

	function get_bulk_actions() {
		global $comment_status;

		$actions = array();
		if ( in_array( $comment_status, array( 'all', 'approved' ) ) )
			$actions['unapprove'] = __( 'Unapprove' );
		if ( in_array( $comment_status, array( 'all', 'moderated' ) ) )
			$actions['approve'] = __( 'Approve' );
		if ( in_array( $comment_status, array( 'all', 'moderated', 'approved' ) ) )
			$actions['spam'] = _x( 'Mark as Spam', 'comment' );

		if ( 'trash' == $comment_status )
			$actions['untrash'] = __( 'Restore' );
		elseif ( 'spam' == $comment_status )
			$actions['unspam'] = _x( 'Not Spam', 'comment' );

		if ( in_array( $comment_status, array( 'trash', 'spam' ) ) || !EMPTY_TRASH_DAYS )
			$actions['delete'] = __( 'Delete Permanently' );
		else
			$actions['trash'] = __( 'Move to Trash' );

		return $actions;
	}

	function extra_tablenav( $which ) {
		global $comment_status, $comment_type;
		echo '<div class="alignleft actions">';
		if ( 'top' == $which ) {
			echo '
				<select name="comment_type">
					<option value="">'. _e( 'Show all comment types' ).'</option>';

					$comment_types = apply_filters( 'admin_comment_types_dropdown', array(
						'comment' => __( 'Comments' ),
						'pings' => __( 'Pings' ),
					) );

					foreach ( $comment_types as $type => $label )
						echo "\t<option value='" . esc_attr( $type ) . "'" . selected( $comment_type, $type, false ) . ">$label</option>\n";
			echo '
				</select>';
			submit_button( __( 'Filter' ), 'button', false, false, array( 'id' => 'post-query-submit' ) );
		}

		if ( ( 'spam' == $comment_status || 'trash' == $comment_status ) && current_user_can( 'moderate_comments' ) ) {
			wp_nonce_field( 'bulk-destroy', '_destroy_nonce' );
			$title = ( 'spam' == $comment_status ) ? esc_attr__( 'Empty Spam' ) : esc_attr__( 'Empty Trash' );
			submit_button( $title, 'apply', 'delete_all', false );
		}
		/**
		 * Fires after the Filter submit button for comment types.
		 *
		 * @since 2.5.0
		 *
		 * @param string $comment_status The comment status name. Default 'All'.
		 */
		do_action( 'manage_comments_nav', $comment_status );
		echo '</div>';
	}

	function current_action() {
		if ( isset( $_REQUEST['delete_all'] ) || isset( $_REQUEST['delete_all2'] ) )
			return 'delete_all';

		return parent::current_action();
	}

	function get_column_info() {
		$this->_column_headers = array(
			array(
			'cb'       => 'x',
			'author'   => __( 'Author' ),
			'comment'  => _x( 'Comment', 'column name' ),
			'response'     => __( 'in Response to' ),
			),
			array(),
			array(),
		);

		return $this->_column_headers;
	}

	function get_columns() {
		global $post_id;

		$columns = array();

		$columns['cb'] = '<input type="checkbox" />';
		$columns['author'] = __( 'Author' );
		$columns['comment'] = _x( 'Comment', 'column name' );
		$columns['response'] = _x( 'In Response To', 'column name' );

		return $columns;
	}

	function get_sortable_columns() {
		return array(
			'author'   => 'comment_author',
			'response' => 'comment_post_ID'
		);
	}


	function display() {
		extract( $this->_args );

		wp_nonce_field( "fetch-list-" . get_class( $this ), '_ajax_fetch_list_nonce' );

		$this->display_tablenav( 'top' );

?>
<table class="<?php echo implode( ' ', $this->get_table_classes() ); ?>" cellspacing="0">
	<thead>
	<tr>
		<?php $this->print_column_headers(); ?>
	</tr>
	</thead>

	<tfoot>
	<tr>
		<?php $this->print_column_headers( false ); ?>
	</tr>
	</tfoot>

	<tbody id="the-comment-list" data-wp-lists="list:comment">
		<?php $this->display_rows_or_placeholder(); ?>
	</tbody>

	<tbody id="the-extra-comment-list" data-wp-lists="list:comment" style="display: none;">
		<?php $this->items = $this->extra_items; $this->display_rows(); ?>
	</tbody>
</table>
<?php

		$this->display_tablenav( 'bottom' );
	}

	function single_row( $a_comment ) {
		global $post, $comment;

		$comment = $a_comment;
		// this makes it special, get the correct blog for this comment
		switch_to_blog($comment->blog_id);
		$ajaxurl = admin_url() . 'admin-ajax.php';
		$adminurl = admin_url();

		$the_comment_class = wp_get_comment_status( $comment->comment_ID );
		$the_comment_class = join( ' ', get_comment_class( $the_comment_class, $comment->comment_ID, $comment->comment_post_ID ) );

		$post = get_post( $comment->comment_post_ID );

		$this->user_can = current_user_can( 'edit_comment', $comment->comment_ID );
		// attribute data-ajax adds the ajax-url, this is also non-standard and makes ajax-calls possible
		echo "<tr id='comment-$comment->comment_ID' class='$the_comment_class' data-ajax='{$ajaxurl}' data-adminurl='{$adminurl}'>";
		$this->single_row_columns( $comment );
		echo "</tr>\n";
		// make sure we get in the current blog before processing the next row
		restore_current_blog();
	}

	function column_cb( $comment ) {
		// here we add :$blog_id of the comment to the value to be able to switch to that blog on bulk-actions
		if ( $this->user_can ) { ?>
		<label class="screen-reader-text" for="cb-select-<?php echo $comment->comment_ID; ?>"><?php _e( 'Select comment' ); ?></label>
		<input id="cb-select-<?php echo $comment->comment_ID; ?>" type="checkbox" name="delete_comments[]" value="<?php echo $comment->comment_ID.':'.$comment->blog_id; ?>" />
		<?php
		}
	}

	function column_comment( $comment ) {
		global $comment_status;
		$post = get_post();

		$user_can = $this->user_can;

		$comment_url = esc_url( get_comment_link( $comment->comment_ID ) );
		$the_comment_status = wp_get_comment_status( $comment->comment_ID );

		if ( $user_can ) {
			$del_nonce = esc_html( '_wpnonce=' . wp_create_nonce( "delete-comment_$comment->comment_ID" ) );
			$approve_nonce = esc_html( '_wpnonce=' . wp_create_nonce( "approve-comment_$comment->comment_ID" ) );
			// admin_url ervoor plakken, we zitten al in het juiste blog
			$url = admin_url()."comment.php?c=$comment->comment_ID";

			$approve_url = esc_url( $url . "&action=approvecomment&$approve_nonce" );
			$unapprove_url = esc_url( $url . "&action=unapprovecomment&$approve_nonce" );
			$spam_url = esc_url( $url . "&action=spamcomment&$del_nonce" );
			$unspam_url = esc_url( $url . "&action=unspamcomment&$del_nonce" );
			$trash_url = esc_url( $url . "&action=trashcomment&$del_nonce" );
			$untrash_url = esc_url( $url . "&action=untrashcomment&$del_nonce" );
			$delete_url = esc_url( $url . "&action=deletecomment&$del_nonce" );
		}
		$ajaxurl = admin_url() . 'admin-ajax.php';

		echo '<div class="submitted-on">';
		/* translators: 2: comment date, 3: comment time */
		printf( __( 'Submitted on <a href="%1$s">%2$s at %3$s</a>' ), $comment_url,
			/* translators: comment date format. See http://php.net/date */
			get_comment_date( __( 'Y/m/d' ) ),
			get_comment_date( get_option( 'time_format' ) )
		);

		if ( $comment->comment_parent ) {
			$parent = get_comment( $comment->comment_parent );
			$parent_link = esc_url( get_comment_link( $comment->comment_parent ) );
			$name = get_comment_author( $parent->comment_ID );
			printf( ' | '.__( 'In reply to <a href="%1$s">%2$s</a>.' ), $parent_link, $name );
		}

		echo '</div>';
		comment_text();
		if ( $user_can ) { ?>
		<div id="inline-<?php echo $comment->comment_ID; ?>" class="hidden">
		<textarea class="comment" rows="1" cols="1"><?php
			/** This filter is documented in wp-admin/includes/comment.php */
			echo esc_textarea( apply_filters( 'comment_edit_pre', $comment->comment_content ) );
		?></textarea>
		<div class="author-email"><?php echo esc_attr( $comment->comment_author_email ); ?></div>
		<div class="author"><?php echo esc_attr( $comment->comment_author ); ?></div>
		<div class="author-url"><?php echo esc_attr( $comment->comment_author_url ); ?></div>
		<div class="comment_status"><?php echo $comment->comment_approved; ?></div>
		</div>
		<?php
		}

		if ( $user_can ) {
			// preorder it: Approve | Reply | Quick Edit | Edit | Spam | Trash
			$actions = array(
				'approve' => '', 'unapprove' => '',
				'reply' => '',
				'quickedit' => '',
				'edit' => '',
				'spam' => '', 'unspam' => '',
				'trash' => '', 'untrash' => '', 'delete' => ''
			);

			if ( $comment_status && 'all' != $comment_status ) { // not looking at all comments
				if ( 'approved' == $the_comment_status )
					$actions['unapprove'] = "<a href='$unapprove_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID:e7e7d3:action=dim-comment&amp;new=unapproved' class='vim-u vim-destructive' title='" . esc_attr__( 'Unapprove this comment' ) . "'>" . __( 'Unapprove' ) . '</a>';
				else if ( 'unapproved' == $the_comment_status )
					$actions['approve'] = "<a href='$approve_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID:e7e7d3:action=dim-comment&amp;new=approved:myajax={$ajaxurl}' class='vim-a vim-destructive' title='" . esc_attr__( 'Approve this comment' ) . "'>" . __( 'Approve' ) . '</a>';
			} else {
				$actions['approve'] = "<a href='$approve_url' data-wp-lists='dim:the-comment-list:comment-$comment->comment_ID:unapproved:e7e7d3:e7e7d3:new=approved:myajax={$ajaxurl}' class='vim-a' title='" . esc_attr__( 'Approve this comment' ) . "'>" . __( 'Approve' ) . '</a>';
				$actions['unapprove'] = "<a href='$unapprove_url' data-wp-lists='dim:the-comment-list:comment-$comment->comment_ID:unapproved:e7e7d3:e7e7d3:new=unapproved' class='vim-u' title='" . esc_attr__( 'Unapprove this comment' ) . "'>" . __( 'Unapprove' ) . '</a>';
			}

			if ( 'spam' != $the_comment_status && 'trash' != $the_comment_status ) {
				$actions['spam'] = "<a href='$spam_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID::spam=1' class='vim-s vim-destructive' title='" . esc_attr__( 'Mark this comment as spam' ) . "'>" . /* translators: mark as spam link */ _x( 'Spam', 'verb' ) . '</a>';
			} elseif ( 'spam' == $the_comment_status ) {
				$actions['unspam'] = "<a href='$unspam_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID:66cc66:unspam=1' class='vim-z vim-destructive'>" . _x( 'Not Spam', 'comment' ) . '</a>';
			} elseif ( 'trash' == $the_comment_status ) {
				$actions['untrash'] = "<a href='$untrash_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID:66cc66:untrash=1' class='vim-z vim-destructive'>" . __( 'Restore' ) . '</a>';
			}

			if ( 'spam' == $the_comment_status || 'trash' == $the_comment_status || !EMPTY_TRASH_DAYS ) {
				$actions['delete'] = "<a href='$delete_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID::delete=1' class='delete vim-d vim-destructive'>" . __( 'Delete Permanently' ) . '</a>';
			} else {
				$actions['trash'] = "<a href='$trash_url' data-wp-lists='delete:the-comment-list:comment-$comment->comment_ID::trash=1' class='delete vim-d vim-destructive' title='" . esc_attr__( 'Move this comment to the trash' ) . "'>" . _x( 'Trash', 'verb' ) . '</a>';
			}

			if ( 'spam' != $the_comment_status && 'trash' != $the_comment_status ) {
				$abs_url = admin_url();
				$actions['edit'] = "<a href='{$abs_url}comment.php?action=editcomment&amp;c={$comment->comment_ID}' title='" . esc_attr__( 'Edit comment' ) . "'>". __( 'Edit' ) . '</a>';
				$actions['quickedit'] = '<a onclick="commentReply.open( \''.$comment->comment_ID.'\',\''.$post->ID.'\',\'edit\' );return false;" class="vim-q" title="'.esc_attr__( 'Quick Edit' ).'" href="#">' . __( 'Quick&nbsp;Edit' ) . '</a>';
				$actions['reply'] = '<a onclick="commentReply.open( \''.$comment->comment_ID.'\',\''.$post->ID.'\' );return false;" class="vim-r" title="'.esc_attr__( 'Reply to this comment' ).'" href="#">' . __( 'Reply' ) . '</a>';
			}
			// akismet uses this hook and bluntly echoes some html in our face
			// we are going to un_hook the plugin and create that links ourselves.
			if(is_plugin_active('akismet/akismet.php'))
			{
				remove_action('comment_row_actions', 'akismet_comment_row_action');
				// run the local variant of this 'akismet-plugin-function'
				nmt_akismet_comment_row_action(array_filter( $actions ), $comment );
			}

			$actions = apply_filters( 'comment_row_actions', array_filter( $actions ), $comment );
			// check if we blacklisted the comment
			if($history = get_comment_meta( $comment->comment_ID, 'sitewide_comment_blacklist_history', true ))
			{
				echo '<span class="akismet-status">| '.$history.' | </span>';
			}

			$i = 0;
			echo '<div class="row-actions">';
			foreach ( $actions as $action => $link ) {
				++$i;
				( ( ( 'approve' == $action || 'unapprove' == $action ) && 2 === $i ) || 1 === $i ) ? $sep = '' : $sep = ' | ';

				// Reply and quickedit need a hide-if-no-js span when not added with ajax
				if ( ( 'reply' == $action || 'quickedit' == $action ) && ! defined('DOING_AJAX') )
					$action .= ' hide-if-no-js';
				elseif ( ( $action == 'untrash' && $the_comment_status == 'trash' ) || ( $action == 'unspam' && $the_comment_status == 'spam' ) ) {
					if ( '1' == get_comment_meta( $comment->comment_ID, '_wp_trash_meta_status', true ) )
						$action .= ' approve';
					else
						$action .= ' unapprove';
				}

				echo "<span class='$action'>$sep$link</span>";
			}
			echo '</div>';
		}
	}

	function column_author( $comment ) {
		global $comment_status;

		$author_url = get_comment_author_url();
		if ( 'http://' == $author_url )
			$author_url = '';
		$author_url_display = preg_replace( '|http://(www\.)?|i', '', $author_url );
		if ( strlen( $author_url_display ) > 50 )
			$author_url_display = substr( $author_url_display, 0, 49 ) . '&hellip;';

		echo "<strong>"; comment_author(); echo '</strong><br />';
		if ( !empty( $author_url ) )
			echo "<a title='$author_url' href='$author_url'>$author_url_display</a><br />";

		if ( $this->user_can ) {
			if ( !empty( $comment->comment_author_email ) ) {
				comment_author_email_link();
				echo '<br />';
			}
			echo '<a href="edit-comments.php?page=nmt-sitewide-moderation&s=';
			comment_author_IP();
			echo '&amp;mode=detail';
			if ( 'spam' == $comment_status )
				echo '&amp;comment_status=spam';
			echo '">';
			comment_author_IP();
			echo '</a>';
		}
	}

	function column_date( $comment ) {
		return get_comment_date( __( 'Y/m/d \a\t g:ia' ) );
	}

	function column_response( $comment ) {
		$post = get_post();

		if ( isset( $this->pending_count[$post->ID.':'.$comment->blog_id] ) ) {
			$pending_comments = $this->pending_count[$post->ID.':'.$comment->blog_id];
		} else {
			$_pending_count_temp = get_pending_comments_num( array( $post->ID ) );
			$pending_comments = $this->pending_count[$post->ID.':'.$comment->blog_id] = $_pending_count_temp[$post->ID];
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$post_link = "<a href='" . get_edit_post_link( $post->ID ) . "'>";
			$post_link .= get_the_title( $post->ID ) . '</a>';
		} else {
			$post_link = get_the_title( $post->ID );
		}

		echo '<div class="response-links"><span class="post-com-count-wrapper">';
		echo "blog: <strong>{$comment->blog_path}</strong><br/>";
		echo $post_link . '<br />';
		$this->comments_bubble( $post->ID, $pending_comments );
		echo '</span> ';
		$post_type_object = get_post_type_object( $post->post_type );
		echo "<a href='" . get_permalink( $post->ID ) . "'>" . $post_type_object->labels->view_item . '</a>';

		echo '</div>';

		if ( 'attachment' == $post->post_type && ( $thumb = wp_get_attachment_image( $post->ID, array( 80, 60 ), true ) ) )
			echo $thumb;
	}

	function column_default( $comment, $column_name ) {
		/**
		 * Fires when the default column output is displayed for a single row.
		 *
		 * @since 2.8.0
		 *
		 * @param string $column_name         The custom column's name.
		 * @param int    $comment->comment_ID The custom column's unique ID number.
		 */
		do_action( 'manage_comments_custom_column', $column_name, $comment->comment_ID );
	}

	function get_comments($args)
	{
		$vq = new WP_VComment_Query();
		return $vq->query($args);
	}
}


// geleend en aangepast van wordpress, ook dit kan beter ...
class WP_VComment_Query {

	var $meta_query = false;
	var $date_query = false;

	function query( $query_vars ) {
		global $wpdb;

		$defaults = array(
			'author_email' => '',
			'ID' => '',
			'karma' => '',
			'number' => '',
			'offset' => '',
			'orderby' => '',
			'order' => 'DESC',
			'parent' => '',
			'post_ID' => '',
			'post_id' => 0,
			'post_author' => '',
			'post_name' => '',
			'post_parent' => '',
			'post_status' => '',
			'post_type' => '',
			'status' => '',
			'type' => '',
			'user_id' => '',
			'search' => '',
			'count' => false,
			'meta_key' => '',
			'meta_value' => '',
			'meta_query' => '',
			'date_query' => null, // See WP_Date_Query
		);

		$groupby = '';

		$this->query_vars = wp_parse_args( $query_vars, $defaults );

		// Parse meta query
		$this->meta_query = new WP_Meta_Query();
		$this->meta_query->parse_query_vars( $this->query_vars );

		extract( $this->query_vars, EXTR_SKIP );

		// $args can be whatever, only use the args defined in defaults to compute the key
		$key = md5( serialize( compact(array_keys($defaults)) )  );
		$last_changed = wp_cache_get( 'last_changed', 'comment' );
		if ( ! $last_changed ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, 'comment' );
		}
		$cache_key = "get_vcomments:$key:$last_changed";

		if ( $cache = wp_cache_get( $cache_key, 'comment' ) )
			return $cache;

		$post_id = absint($post_id);

		if ( 'hold' == $status )
			$approved = "comment_approved = '0'";
		elseif ( 'approve' == $status )
			$approved = "comment_approved = '1'";
		elseif ( ! empty( $status ) && 'all' != $status )
			$approved = $wpdb->prepare( "comment_approved = %s", $status );
		else
			$approved = "( comment_approved = '0' OR comment_approved = '1' )";

		$order = ( 'ASC' == strtoupper($order) ) ? 'ASC' : 'DESC';

		if ( ! empty( $orderby ) ) {
			$ordersby = is_array($orderby) ? $orderby : preg_split('/[,\s]/', $orderby);
			$allowed_keys = array(
				'comment_agent',
				'comment_approved',
				'comment_author',
				'comment_author_email',
				'comment_author_IP',
				'comment_author_url',
				'comment_content',
				'comment_date',
				'comment_date_gmt',
				'comment_ID',
				'comment_karma',
				'comment_parent',
				'comment_post_ID',
				'comment_type',
				'user_id',
			);
			if ( ! empty( $this->query_vars['meta_key'] ) ) {
				$allowed_keys[] = $this->query_vars['meta_key'];
				$allowed_keys[] = 'meta_value';
				$allowed_keys[] = 'meta_value_num';
			}
			$ordersby = array_intersect( $ordersby, $allowed_keys );
			foreach ( $ordersby as $key => $value ) {
				if ( $value == $this->query_vars['meta_key'] || $value == 'meta_value' ) {
					$ordersby[ $key ] = "$wpdb->commentmeta.meta_value";
				} elseif ( $value == 'meta_value_num' ) {
					$ordersby[ $key ] = "$wpdb->commentmeta.meta_value+0";
				}
			}
			$orderby = empty( $ordersby ) ? 'comment_date_gmt' : implode(', ', $ordersby);
		} else {
			$orderby = 'comment_date_gmt';
		}

		$number = absint($number);
		$offset = absint($offset);

		if ( !empty($number) ) {
			if ( $offset )
				$limits = 'LIMIT ' . $offset . ',' . $number;
			else
				$limits = 'LIMIT ' . $number;
		} else {
			$limits = '';
		}

		$fields = ( $count ) ? 'COUNT(*)' : '*';

		$join = '';
		$where = $approved;

		if ( ! empty($post_id) )
			$where .= $wpdb->prepare( ' AND comment_post_ID = %d', $post_id );
		if ( '' !== $author_email )
			$where .= $wpdb->prepare( ' AND comment_author_email = %s', $author_email );
		if ( '' !== $karma )
			$where .= $wpdb->prepare( ' AND comment_karma = %d', $karma );
		if ( 'comment' == $type ) {
			$where .= " AND comment_type = ''";
		} elseif( 'pings' == $type ) {
			$where .= ' AND comment_type IN ("pingback", "trackback")';
		} elseif ( ! empty( $type ) ) {
			$where .= $wpdb->prepare( ' AND comment_type = %s', $type );
		}
		if ( '' !== $parent )
			$where .= $wpdb->prepare( ' AND comment_parent = %d', $parent );
		if ( '' !== $user_id )
			$where .= $wpdb->prepare( ' AND user_id = %d', $user_id );
		if ( '' !== $search )
			$where .= $this->get_search_sql( $search, array( 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_content' ) );

		$post_fields = array_filter( compact( array( 'post_author', 'post_name', 'post_parent', 'post_status', 'post_type', ) ) );
		if ( ! empty( $post_fields ) ) {
			$join = "JOIN $wpdb->posts ON $wpdb->posts.ID = wp_all_comments.comment_post_ID";
			foreach( $post_fields as $field_name => $field_value )
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.{$field_name} = %s", $field_value );
		}

/*		if ( ! empty( $this->meta_query->queries ) ) {
			$clauses = $this->meta_query->get_sql( 'comment', $wpdb->comments, 'comment_ID', $this );
			$join .= $clauses['join'];
			$where .= $clauses['where'];
			$groupby = "{$wpdb->comments}.comment_ID";
		}

		if ( ! empty( $date_query ) && is_array( $date_query ) ) {
			$date_query_object = new WP_Date_Query( $date_query, 'comment_date' );
			$where .= $date_query_object->get_sql();
		}

		$pieces = array( 'fields', 'join', 'where', 'orderby', 'order', 'limits', 'groupby' );

		foreach ( $pieces as $piece )
			$$piece = isset( $clauses[ $piece ] ) ? $clauses[ $piece ] : '';
*/
		if ( $groupby )
			$groupby = 'GROUP BY ' . $groupby;

		$query = "SELECT $fields FROM wp_all_comments $join WHERE $where $groupby ORDER BY $orderby $order $limits";

		if ( $count )
			return $wpdb->get_var( $query );

		$comments = $wpdb->get_results( $query );

		wp_cache_add( $cache_key, $comments, 'comment' );

		return $comments;
	}

	function get_search_sql( $string, $cols ) {
		$string = esc_sql( like_escape( $string ) );

		$searches = array();
		foreach ( $cols as $col )
			$searches[] = "$col LIKE '%$string%'";

		return ' AND (' . implode(' OR ', $searches) . ')';
	}
}

// local akismet copy
function nmt_akismet_comment_row_action( $a, $comment ) {

	// failsafe for old WP versions
	if ( !function_exists('add_comment_meta') )
		return $a;

	$abs_url = admin_url();
	$akismet_result = get_comment_meta( $comment->comment_ID, 'akismet_result', true );
	$akismet_error  = get_comment_meta( $comment->comment_ID, 'akismet_error', true );
	$user_result    = get_comment_meta( $comment->comment_ID, 'akismet_user_result', true);
	$comment_status = wp_get_comment_status( $comment->comment_ID );
	$desc = null;
	if ( $akismet_error ) {
		$desc = __( 'Awaiting spam check' );
	} elseif ( !$user_result || $user_result == $akismet_result ) {
		// Show the original Akismet result if the user hasn't overridden it, or if their decision was the same
		if ( $akismet_result == 'true' && $comment_status != 'spam' && $comment_status != 'trash' )
			$desc = __( 'Flagged as spam by Akismet' );
		elseif ( $akismet_result == 'false' && $comment_status == 'spam' )
			$desc = __( 'Cleared by Akismet' );
	} else {
		$who = get_comment_meta( $comment->comment_ID, 'akismet_user', true );
		if ( $user_result == 'true' )
			$desc = sprintf( __('Flagged as spam by %s'), $who );
		else
			$desc = sprintf( __('Un-spammed by %s'), $who );
	}

	// add a History item to the hover links, just after Edit
	if ( $akismet_result ) {
		$b = array();
		foreach ( $a as $k => $item ) {
			$b[ $k ] = $item;
			if (
				$k == 'edit'
				|| ( $k == 'unspam' && $GLOBALS['wp_version'] >= 3.4 )
			) {
				$b['history'] = '<a href="comment.php?action=editcomment&amp;c='.$comment->comment_ID.'#akismet-status" title="'. esc_attr__( 'View comment history' ) . '"> '. __('History') . '</a>';
			}
		}

		$a = $b;
	}

	if ( $desc )
		echo '<span class="akismet-status" commentid="'.$comment->comment_ID.'"><a href="'.$abs_url.'comment.php?action=editcomment&amp;c='.$comment->comment_ID.'#akismet-status" title="' . esc_attr__( 'View comment history' ) . '">'.esc_html( $desc ).'</a></span>';

	if ( apply_filters( 'akismet_show_user_comments_approved', get_option('akismet_show_user_comments_approved') ) == 'true' ) {
		$comment_count = akismet_get_user_comments_approved( $comment->user_id, $comment->comment_author_email, $comment->comment_author, $comment->comment_author_url );
		$comment_count = intval( $comment_count );
		echo '<span class="akismet-user-comment-count" commentid="'.$comment->comment_ID.'" style="display:none;"><br><span class="akismet-user-comment-counts">'.sprintf( _n( '%s approved', '%s approved', $comment_count ), number_format_i18n( $comment_count ) ) . '</span></span>';
	}

	return $a;
}