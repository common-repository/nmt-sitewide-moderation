<?php
/*
Plugin Name: NMT Sitewide Comment moderation
Description: Enables site-wide comment moderation over multiple blogs.
Version: 1.1
Author: Appie Verschoor, NMT
Author URI:

History:
version 0.1
First attempt was a view; a union over all comments tables in the database, which worked perfectly. Except for performance. Our site has 100.000+ comments scattered over 50 blogs, and opening the screen took about 30 secs, counting the comments alone took 10 secs.

So version 0.2 is the same plugin, mostly, only each and every comment is duplicated into a real table, which can have real indexes. Performance is up to par, slightly better when filtering on author_IP since wordpress does not have an index on that column. The tricky part was off course to keep both comments and the all_comments duplicates in sync.
All actions are done on the real comments tables. Using normal wordpress functionality, hooking in the appropriate actions I hope to have covered all possible changes to the comments tables and have them inserted/deleted/updated in this mirror table as well.

1.1
Some minor problems with syncing the comments
On some installations the bulk-edit functions resulted in an 'empty' screen because the redirect came too late, headers already sent errors. noheader=true solves this problem.

*/

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}
// this is our own implementation of the list-table, using the sitewide-comments table
require_once('nmt_comment_list_table.php');


add_action ('admin_menu', 'sitewide_moderation_menu');
register_activation_hook(__FILE__, 'create_view');

/**
 * nmt-sitewide-moderation-plus-plus
 *
 * Moderate comments over all blogs with comments enabled
 *
 */


function sitewide_moderation_menu()
{
	add_comments_page( 'NMT Sitewide moderation', 'Sitewide moderation', 'moderate_comments', 'nmt-sitewide-moderation', 'nmt_sitewide_moderation');
	add_comments_page( 'NMT Sitewide blacklist', 'Sitewide blacklist', 'moderate_comments', 'nmt-sitewide-blacklist', 'nmt_sitewide_blacklist_options');
}

// The mother of all comment-moderations
// shows the comments-table in the admin section
function nmt_sitewide_moderation()
{
	global $the_list, $wpdb;

	// eigen varianten van wordpress javascripts met subtiele wijzigingen, o.a. in ajaxurl die per blog moet wijzigen
	wp_register_script('nmt-sitewide-moderation-list', plugins_url('nmt-sitewide-wp-lists.js', __FILE__ ));
	wp_register_script('nmt-sitewide-moderation', plugins_url('nmt-sitewide-edit-comments.js', __FILE__ ));
	wp_localize_script( 'common', 'commonL10n', array(
		'warnDelete' => __("You are about to permanently delete the selected items.\n  'Cancel' to stop, 'OK' to delete.")));
	wp_localize_script( 'nmt-sitewide-moderation', 'adminCommentsL10n', array(
			'hotkeys_highlight_first' => isset($_GET['hotkeys_highlight_first']),
			'hotkeys_highlight_last' => isset($_GET['hotkeys_highlight_last']),
			'replyApprove' => __( 'Approve and Reply' ),
			'reply' => __( 'Reply' )
		) );
	wp_enqueue_script('wp-ajax-response'); //which will give us the wpAjax object
	wp_enqueue_script('nmt-sitewide-moderation-list');
	wp_enqueue_script('nmt-sitewide-moderation');
	enqueue_comment_hotkeys_js();

	$the_list = new NMT_comment_moderation_list();
	$pagenum = $the_list->get_pagenum();
	$doaction = $the_list->current_action();
	$comment_status = wp_unslash( $_REQUEST['comment_status'] );

	if ($doaction)
	{
		// we need to bulk-edit some comments, let's do this
		check_admin_referer( 'bulk-comments' );

		if ( 'delete_all' == $doaction && !empty( $_REQUEST['pagegen_timestamp'] ) ) {
			$delete_time = wp_unslash( $_REQUEST['pagegen_timestamp'] );
			$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT(comment_ID, ':', blog_id) FROM wp_all_comments WHERE comment_approved = %s AND %s > comment_date_gmt", $comment_status, $delete_time ) );
			$doaction = 'delete';
		} elseif ( isset( $_REQUEST['delete_comments'] ) ) {
			$comment_ids = $_REQUEST['delete_comments'];
			$doaction = ( $_REQUEST['action'] != -1 ) ? $_REQUEST['action'] : $_REQUEST['action2'];
		} elseif ( isset( $_REQUEST['ids'] ) ) {
			$comment_ids = array_map( 'absint', explode( ',', $_REQUEST['ids'] ) );
		} elseif ( wp_get_referer() ) {
			wp_safe_redirect( wp_get_referer() );
			exit;
		}

		$approved = $unapproved = $spammed = $unspammed = $trashed = $untrashed = $deleted = 0;

		$redirect_to = remove_query_arg( array( 'trashed', 'untrashed', 'deleted', 'spammed', 'unspammed', 'approved', 'unapproved', 'ids', '_wp_http_referer', '_wp_nonce', '_ajax_fetch_list_nonce', '_destroy_nonce', '_total', '_per_page' ), wp_get_referer() );
		$redirect_to = add_query_arg( 'paged', $pagenum, $redirect_to );

		foreach ( $comment_ids as $comment_id_mixed ) { // Check the permissions on each
			$comment_id = explode(':', $comment_id_mixed);
			$blog_id = $comment_id[1];
			$comment_id = $comment_id[0];
			// we need to go to the correct blog to delete the right comment
			switch_to_blog($blog_id);
			if ( !current_user_can( 'edit_comment', $comment_id ) )
			{
				restore_current_blog();
				continue;
			}

			switch ( $doaction ) {
				case 'approve' :
					wp_set_comment_status( $comment_id, 'approve' );
					$approved++;
					break;
				case 'unapprove' :
					wp_set_comment_status( $comment_id, 'hold' );
					$unapproved++;
					break;
				case 'spam' :
					wp_spam_comment( $comment_id );
					$spammed++;
					break;
				case 'unspam' :
					wp_unspam_comment( $comment_id );
					$unspammed++;
					break;
				case 'trash' :
					wp_trash_comment( $comment_id );
					$trashed++;
					break;
				case 'untrash' :
					wp_untrash_comment( $comment_id );
					$untrashed++;
					break;
				case 'delete' :
					wp_delete_comment( $comment_id );
					$deleted++;
					break;
			}
			// and we need to get back each time because current_blog travels to the latest blog used ...
			restore_current_blog();
		}

		if ( $approved )
			$redirect_to = add_query_arg( 'approved', $approved, $redirect_to );
		if ( $unapproved )
			$redirect_to = add_query_arg( 'unapproved', $unapproved, $redirect_to );
		if ( $spammed )
			$redirect_to = add_query_arg( 'spammed', $spammed, $redirect_to );
		if ( $unspammed )
			$redirect_to = add_query_arg( 'unspammed', $unspammed, $redirect_to );
		if ( $trashed )
			$redirect_to = add_query_arg( 'trashed', $trashed, $redirect_to );
		if ( $untrashed )
			$redirect_to = add_query_arg( 'untrashed', $untrashed, $redirect_to );
		if ( $deleted )
			$redirect_to = add_query_arg( 'deleted', $deleted, $redirect_to );
		if ( $trashed || $spammed )
			$redirect_to = add_query_arg( 'ids', join( ',', $comment_ids ), $redirect_to );

		wp_safe_redirect( $redirect_to );
		exit;
	}

	// We are done, we can start the output!
	echo '<div class="wrap">
	<h2>NMT Sitewide comment moderation</h2>';

	// let's query the database to find all comments in all blogs
	$the_list->prepare_items();
	// prepare the views
	$the_list->views();
	?>
	<style>
		.akismet-status {
    	float: right;
    	color: #AAAAAA;
    	font-style: italic;
		}
		.akismet-status a {
    	color: #AAAAAA;
    	font-style: italic;
		}
		.widefat a {
    	text-decoration: none;
		}
	</style>
	<form id="comments-form" action="" method="get">

<?php $the_list->search_box( __( 'Search Comments' ), 'comment' ); ?>

<?php if ( $post_id ) : ?>
<input type="hidden" name="p" value="<?php echo esc_attr( intval( $post_id ) ); ?>" />
<?php endif; ?>
<input type="hidden" name="comment_status" value="<?php echo esc_attr($comment_status); ?>" />
<input type="hidden" name="pagegen_timestamp" value="<?php echo esc_attr(current_time('mysql', 1)); ?>" />
<?php // this one takes us right back to this plugin instead of edit-comments, this is essential for the plugin
?><input type="hidden" name="page" value="nmt-sitewide-moderation" />
<input type="hidden" name="noheader" value="true" />

<input type="hidden" name="_total" value="<?php echo esc_attr( $the_list->get_pagination_arg('total_items') ); ?>" />
<input type="hidden" name="_per_page" value="<?php echo esc_attr( $the_list->get_pagination_arg('per_page') ); ?>" />
<input type="hidden" name="_page" value="<?php echo esc_attr( $the_list->get_pagination_arg('page') ); ?>" />

<?php if ( isset($_REQUEST['paged']) ) { ?>
	<input type="hidden" name="paged" value="<?php echo esc_attr( absint( $_REQUEST['paged'] ) ); ?>" />
<?php }
	// show what we've got ...
	$the_list->display(); ?>
</form>
	<?php

	echo '</div>';
	echo '<div id="ajax-response"></div>';
	wp_comment_reply('-1', true, 'detail');
	wp_comment_trashnotice();

}

//
// the other admin-page
// Blacklisting email, ip, websites
function nmt_sitewide_blacklist_options() {
	echo '<div class="wrap">';
	echo '<div id="icon-edit-comments" class="icon32"></div>';
	echo '<h2>Sitewide blacklist</h2>';
	if (isset($_POST['save']))
	{
		update_site_option('nmt_sitewide_blacklist', $_POST['nmt_sitewide_blacklist']);
		echo '<div id="message" class="updated fade"><p><strong>Blacklist updated</strong></p></div>';
	}
	echo '<form method="post" width="1">';
	echo '  <textarea name="nmt_sitewide_blacklist" cols="40" rows="45">';
	echo esc_textarea(get_site_option('nmt_sitewide_blacklist'));
	echo '  </textarea>';
	echo '  <div class="nmt_help"><label for="nmt_sitewide_blacklist">IP-adressen, websiteURL\'s, emailadressen die niet op onze website reacties mogen achterlaten. <br/>E&egrave;n argument per regel.</label></div>';
	echo '  <input type="hidden" name="save" value="1" />';
	echo '  <p><input class="button-primary" type="submit" name="update" value="Update Options" id="submitbutton" /></p>';
	echo '</form>';
	echo '</div>';
	echo '<style>.nmt_help {float:left; width: 250px;}</style>';
}


function nmt_sitewide_blacklist($comment)
{
	// if it's a pingpack / trackback, leave it alone
	if ($comment['comment_type'] != '')
		return $comment;
	// logged in users can do no harm
	get_currentuserinfo();
	if ( is_user_logged_in() )
		return $comment;
	$db = get_site_option('nmt_sitewide_blacklist');
	$blacklist_array = explode("\n", $db);
	$commenter_ip = preg_replace( '/[^0-9a-fA-F:., ]/', '',$_SERVER['REMOTE_ADDR'] );

	foreach((array)$blacklist_array as $blacklist)
	{
		$blacklist = trim($blacklist);
		if( empty( $blacklist ) )
			continue;

		if (trim(strtolower($comment['comment_author']))       == strtolower($blacklist) ||
		    trim(strtolower($comment['comment_author_email'])) == strtolower($blacklist) ||
		    trim(strtolower($comment['comment_author_url']))   == strtolower($blacklist) ||
		    trim($commenter_ip)                                == $blacklist
		   )
		{ // spam this right away
			$time = current_time('mysql'); // Get the date
			$the_comment = array(
						'comment_post_ID'      => $comment['comment_post_ID'],
						'comment_author'       => $comment['comment_author'],
						'comment_author_email' => $comment['comment_author_email'],
						'comment_author_url'   => $comment['comment_author_url'],
						'comment_content'      => $comment['comment_content'],
						'comment_type'         => '', // it's a comment
						'comment_parent'       => $comment['comment_parent'],
						'user_id'              => 0, // otherwise we would have accepted this comment
						'comment_author_IP'    => $commenter_ip,
						'comment_agent'        => $_SERVER['HTTP_USER_AGENT'],
						'comment_date'         => $time,
						'comment_approved'     => 'spam',
					);
			$the_comment = wp_filter_comment($the_comment);

			$spam_comment_id = wp_insert_comment($the_comment);

			nmt_sitewide_blacklist_comment_history($spam_comment_id, 'comment marked as spam by sitewide moderation');
			wp_safe_redirect( $_SERVER['HTTP_REFERER'] );
			die;
		}
	}
	// if we got here we're cool, let it pass to wordpress again
	return ($comment);
}

// log an event for a given comment, storing it in comment_meta
function nmt_sitewide_blacklist_comment_history( $comment_id, $message) {
	add_comment_meta( $comment_id, 'sitewide_comment_blacklist_history', $message, true );
}

// Hook this plugin into the comment submission process and send it to the blacklist
add_action ('preprocess_comment', 'nmt_sitewide_blacklist', 1);


// multi-site AJAX comment-moderation handlers
add_action( 'wp_ajax_edit-comment-multi', 'wp_ajax_edit_comment_multi' );
add_action( 'wp_ajax_replyto-comment-multi', 'wp_ajax_replyto_comment_multi' );

// mostly a copy of the original function,
// different list though.
function wp_ajax_edit_comment_multi() {
	global $the_list;
	global $current_blog;

	check_ajax_referer( 'replyto-comment', '_ajax_nonce-replyto-comment' );

	$comment_id = (int) $_POST['comment_ID'];
	if ( ! current_user_can( 'edit_comment', $comment_id ) )
		wp_die( 'this is not possible?'  );

	if ( '' == $_POST['content'] )
		wp_die( __( 'ERROR: please type a comment.' ) );

	if ( isset( $_POST['status'] ) )
		$_POST['comment_status'] = $_POST['status'];
	edit_comment();

	$position = ( isset($_POST['position']) && (int) $_POST['position']) ? (int) $_POST['position'] : '-1';
	$comments_status = isset($_POST['comments_listing']) ? $_POST['comments_listing'] : '';

	$checkbox = ( isset($_POST['checkbox']) && true == $_POST['checkbox'] ) ? 1 : 0;
	//$the_list = _get_list_table( 'NMT_comment_moderation_list', array( 'screen' => 'edit-comments' ) );
	$the_list = new NMT_comment_moderation_list(array( 'screen' => 'edit-comments' ));
	// remember, when doing AJAX we are in the correct blog so we don't need to query the view.
	// BUT, we'd better add the extra-view attributes to the comment after we've fetched it!
	$comment = get_comment( $comment_id );
	if ( empty( $comment->comment_ID ) )
		wp_die( -1 );
	$comment->blog_path = $current_blog->path;
	$comment->blog_id = $current_blog->blog_id;
	// site_id and domain are never referenced, so why bother ...
	ob_start();
	$the_list->single_row( $comment );
	$comment_list_item = ob_get_clean();

	$x = new WP_Ajax_Response();

	$x->add( array(
		'what' => 'edit_comment',
		'id' => $comment->comment_ID,
		'data' => $comment_list_item,
		'position' => $position
	));

	$x->send();
}

function wp_ajax_replyto_comment_multi( $action ) {
	global $wp_list_table, $wpdb, $current_blog;
	if ( empty( $action ) )
		$action = 'replyto-comment';

	check_ajax_referer( $action, '_ajax_nonce-replyto-comment' );

	$comment_post_ID = (int) $_POST['comment_post_ID'];
	$post = get_post( $comment_post_ID );
	if ( ! $post )
		wp_die( -1 );

	if ( !current_user_can( 'edit_post', $comment_post_ID ) )
		wp_die( -1 );

	if ( empty( $post->post_status ) )
		wp_die( 1 );
	elseif ( in_array($post->post_status, array('draft', 'pending', 'trash') ) )
		wp_die( __('ERROR: you are replying to a comment on a draft post.') );

	$user = wp_get_current_user();
	if ( $user->exists() ) {
		$user_ID = $user->ID;
		$comment_author       = wp_slash( $user->display_name );
		$comment_author_email = wp_slash( $user->user_email );
		$comment_author_url   = wp_slash( $user->user_url );
		$comment_content      = trim($_POST['content']);
		if ( current_user_can( 'unfiltered_html' ) ) {
			if ( ! isset( $_POST['_wp_unfiltered_html_comment'] ) )
				$_POST['_wp_unfiltered_html_comment'] = '';

			if ( wp_create_nonce( 'unfiltered-html-comment' ) != $_POST['_wp_unfiltered_html_comment'] ) {
				kses_remove_filters(); // start with a clean slate
				kses_init_filters(); // set up the filters
			}
		}
	} else {
		wp_die( __( 'Sorry, you must be logged in to reply to a comment.' ) );
	}

	if ( '' == $comment_content )
		wp_die( __( 'ERROR: please type a comment.' ) );

	$comment_parent = 0;
	if ( isset( $_POST['comment_ID'] ) )
		$comment_parent = absint( $_POST['comment_ID'] );
	$comment_auto_approved = false;
	$commentdata = compact('comment_post_ID', 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_content', 'comment_type', 'comment_parent', 'user_ID');

	// automatically approve parent comment
	if ( !empty($_POST['approve_parent']) ) {
		$parent = get_comment( $comment_parent );

		if ( $parent && $parent->comment_approved === '0' && $parent->comment_post_ID == $comment_post_ID ) {
			if ( wp_set_comment_status( $parent->comment_ID, 'approve' ) )
				$comment_auto_approved = true;
		}
	}

	$comment_id = wp_new_comment( $commentdata );
	$comment = get_comment($comment_id);
	if ( ! $comment ) wp_die( 1 );
	$comment->blog_path = $current_blog->path;
	$comment->blog_id = $current_blog->blog_id;

	$position = ( isset($_POST['position']) && (int) $_POST['position'] ) ? (int) $_POST['position'] : '-1';

	ob_start();
	$the_list = new NMT_comment_moderation_list(array( 'screen' => 'edit-comments' ));
	$the_list->single_row( $comment );

	$comment_list_item = ob_get_clean();

	$response =  array(
		'what' => 'comment',
		'id' => $comment->comment_ID,
		'data' => $comment_list_item,
		'position' => $position
	);

	if ( $comment_auto_approved )
		$response['supplemental'] = array( 'parent_approved' => $parent->comment_ID );

	$x = new WP_Ajax_Response();
	$x->add( $response );
	$x->send();
}

/*
 * Creates the view over all comments
 */
function create_view()
{
	global $wpdb;
	$wpdb->query('DROP TABLE wp_all_comments');

	$view_query = "
	CREATE TABLE IF NOT EXISTS `wp_all_comments` (
  `blog_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `site_id` bigint(10) unsigned NOT NULL DEFAULT '0',
  `blog_domain` varchar(100) NOT NULL DEFAULT '',
  `blog_path` varchar(200) NOT NULL DEFAULT '',
  `comment_ID` bigint(20) unsigned NOT NULL DEFAULT '0',
  `comment_post_ID` bigint(20) unsigned NOT NULL DEFAULT '0',
  `comment_author` tinytext NOT NULL,
  `comment_author_email` varchar(100) NOT NULL DEFAULT '',
  `comment_author_url` varchar(200) NOT NULL DEFAULT '',
  `comment_author_IP` varchar(100) NOT NULL DEFAULT '',
  `comment_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `comment_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `comment_content` text NOT NULL,
  `comment_karma` int(11) NOT NULL DEFAULT '0',
  `comment_approved` varchar(20) NOT NULL DEFAULT '1',
  `comment_agent` varchar(255) NOT NULL DEFAULT '',
  `comment_type` varchar(20) NOT NULL DEFAULT '',
  `comment_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  KEY `comment_id` (`comment_ID`),
  KEY `comment_post_ID` (`comment_post_ID`),
  KEY `comment_approved_date_gmt` (`comment_approved`,`comment_date_gmt`),
  KEY `comment_date_gmt` (`comment_date_gmt`),
  KEY `comment_parent` (`comment_parent`),
  KEY `comment_spammer` (`comment_author_IP`),
  UNIQUE KEY `blog_comment` (`blog_id`, `comment_ID`)
	) ";
	$wpdb->query($view_query);

	fill_current_state();
}

function fill_current_state()
{
	global $wpdb;
	// transfer all current comments in all blogs to the 'views'-table
	$blogs = $wpdb->get_results("SELECT blog_id, site_id, domain, path FROM {$wpdb->blogs} WHERE archived = '0' AND mature = 0 AND public = 1 AND spam = 0 AND deleted = 0");

	foreach ($blogs as $blog)
	{
		switch_to_blog($blog->blog_id); // this is the only way to get the correct prefixes for the tables

		$comments = $wpdb->get_results("select * from {$wpdb->prefix}comments ");
		foreach($comments as $comment)
		{
			nmt_insert_comment($comment->comment_ID, $comment, (array)$blog );
		}

		restore_current_blog();
	}

}


/*
 * these functions run within the respective blogs where the comment is added, deleted or modified
 * so whe know for which blog we are currently working. This information is needed to maintain the
 * integrity of the wp_all_comments table
 */

add_action('delete_comment', 'nmt_delete_comment', 99, 2);
add_action('edit_comment', 'nmt_edit_comment');
add_action('wp_insert_comment', 'nmt_insert_comment', 99, 2);
add_action('wp_set_comment_status', 'nmt_update_comment_status');

function nmt_update_comment_status($comment_id, $comment_status = '' )
{
	// beware, when in 'batch-mode' $current-blog is always pointing to the blog you're in
	// which is wrong when you are deleting or spamming dozens of comments in different blogs.
	global $wpdb;
	$blog_id = get_current_blog_id();
	$the_comment = get_comment($comment_id);
	if (isset($the_comment->comment_ID))
	{
		$wpdb->query("update wp_all_comments
	                 set comment_approved = '{$the_comment->comment_approved}'
	               where blog_id = {$blog_id}
								   and comment_ID = {$the_comment->comment_ID} ");
	}
}

function nmt_delete_comment($comment_id)
{
	// beware, when in 'batch-mode' $current-blog is always pointing to the blog you're in
	// which is wrong when you are deleting or spamming dozens of comments in different blogs.
	global $wpdb;
	$blog_id = get_current_blog_id();
	$wpdb->query("delete from wp_all_comments where blog_id = {$blog_id} and comment_ID = {$comment_id}");
}

function nmt_edit_comment($comment_id)
{
	global $current_blog, $wpdb;
	$the_comment = get_comment($comment_id);
	$c_author  = addslashes($the_comment->comment_author);
	$c_content = addslashes($the_comment->comment_content);
	$c_agent   = addslashes($the_comment->comment_agent);

	$wpdb->query("update wp_all_comments set
										comment_ID = {$the_comment->comment_ID},
										comment_post_ID = {$the_comment->comment_post_ID},
										comment_author = '{$c_author}',
										comment_author_email = '{$the_comment->comment_author_email}',
										comment_author_IP = '{$the_comment->comment_author_IP}',
										comment_author_url = '{$the_comment->comment_author_url}',
										comment_date = '{$the_comment->comment_date}',
										comment_date_gmt = '{$the_comment->comment_date_gmt}',
										comment_content = '{$c_content}',
										comment_karma = '{$the_comment->comment_karma}',
										comment_approved = '{$the_comment->comment_approved}',
										comment_agent = '{$c_agent}',
										comment_type = '{$the_comment->comment_type}',
										comment_parent = {$the_comment->comment_parent},
										user_id = {$the_comment->user_id}
								where blog_id = {$current_blog->blog_id}
								  and comment_ID = {$the_comment->comment_ID} ");
}

function nmt_insert_comment($comment_id, $the_comment, $blog = array())
{
	global $current_blog, $wpdb;
	if(empty($blog['blog_id']))
	{
		$blog = $wpdb->get_row("SELECT blog_id, site_id, domain, path FROM {$wpdb->blogs} WHERE blog_id = {$current_blog->blog_id}", ARRAY_A);
	}
	$c_author  = addslashes($the_comment->comment_author);
	$c_content = addslashes($the_comment->comment_content);
	$c_agent   = addslashes($the_comment->comment_agent);
	$wpdb->query (" insert into wp_all_comments (blog_id, site_id, blog_domain, blog_path, comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_IP, comment_author_url, comment_date, comment_date_gmt, comment_content, comment_karma, comment_approved, comment_agent, comment_type, comment_parent, user_id) values ({$blog['blog_id']}, {$blog['site_id']}, '{$blog['domain']}', '{$blog['path']}', {$the_comment->comment_ID}, {$the_comment->comment_post_ID}, '{$c_author}', '{$the_comment->comment_author_email}', '{$the_comment->comment_author_IP}', '{$the_comment->comment_author_url}', '{$the_comment->comment_date}', '{$the_comment->comment_date_gmt}', '{$c_content}', '{$the_comment->comment_karma}', '{$the_comment->comment_approved}', '{$c_agent}', '{$the_comment->comment_type}', {$the_comment->comment_parent}, {$the_comment->user_id})
	ON DUPLICATE KEY update site_id = {$blog['site_id']}"
	);

}
