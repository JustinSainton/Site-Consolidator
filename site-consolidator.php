<?php
/**
  * Plugin Name: Site Consolidator
  * Plugin URI: https://zaowebdesign.com
  * Description: Site Consolidator allows you to take content from any number of multi-site instances and move them over to a different instance.  It moves comments and posts, maintaining author attribution and properly redirecting image and post links.
  * Version: 0.1
  * Author: Zao
  * Author URI: http://zaowebdesign.com/
  **/

class WP_Site_Consolidator {
	
	/**
	 * Stores the list of posts for the current singular migration
	 *
	 * @since 1.0
	 * @var array
	 * @access private
	 */
	private static $_posts = array();

	/**
	 * Stores taxonomy object for migration.  Array pattern is [tax] => [terms] => [object_ids]
	 *
	 * @since 1.0
	 * @var array
	 * @access private
	 */
	private static $_tax_object = array();

	/**
	 * Stores array of old post IDs and new post IDs for migration.  Pattern is [old_id] => [new_id]
	 *
	 * @since 1.0
	 * @var array
	 * @access private
	 */
	private static $_old_new_relationship = array();

	/**
	 * Stores array of old comment IDs and new comment IDs for migration.  Pattern is [old_id] => [new_id]
	 *
	 * @since 1.0
	 * @var array
	 * @access private
	 */
	private static $_old_new_comments = array();

	/**
	 * What we have here is no failure to communicate
	 * 
	 * We're doing a couple things, really.  Building out a UI to consolidate sites, for one.  Site consolidation is :
	 *  - post, comment migration (this includes parent - child relationships)
	 *  - image, attachment rewrites
	 *  - author attribution 
	 *  - taxonomy migration (this includes parent - child relationships)
	 * 
	 * Not currently running wpmu_delete_blog() against consolidated blogs - though with enough QA, might be worth considering.
	 * 
	 */	
	public static function init() {

		if ( ! is_multisite() || ! is_super_admin() )
			return;

		add_action( 'network_admin_menu'                              , array( __CLASS__, 'add_network_menu' ) );
		add_action( 'wpmu_new_blog'                                   , array( __CLASS__, 'delete_sites_transient' ) );
		add_action( 'delete_blog'                                     , array( __CLASS__, 'delete_sites_transient' ) );
		add_action( 'admin_print_styles-admin_page_site-consolidator' , array( __CLASS__, 'print_styles' ) );
		add_action( 'admin_print_footer_scripts'                      , array( __CLASS__, 'print_scripts' ) );
		add_action( 'load-admin_page_site-consolidator'               , array( __CLASS__, 'process_consolidation' ) );
	}

	/**
	 * Adds 'Consolidate Sites' page to Network 'Updates' menu
	 * @return void
	 */
	public static function add_network_menu() {

		add_submenu_page( 'upgrade.php', __( 'Consolidate Sites', 'site-consolidator' ), __( 'Consolidate Sites', 'site-consolidator' ), 'manage_network', 'site-consolidator', array( __CLASS__, 'site_consolidator_view' ) );
	}

	/**
	 * Deletes transient containing sites. get_sites() method saves potentially expensive database query to transient for 1 week.
	 * This method is executed any time a new site is added or deleted.  
	 * 
	 * @return void
	 */
	public static function delete_sites_transient() {

		delete_transient( 'consolidate_sites_list' );
	}

	/**
	 * Builds out UI for 'Consolidate Sites' page. 
	 * General idea is that you can select multiple sites on the left to be consolidated into one site on the right.
	 * 
	 * @uses wp_nonce_field()
	 * @uses submit_button()
	 * @uses self::get_sites()
	 * @return html
	 */
	public static function site_consolidator_view() {
		?>
		<form method="post">
		<div class="wrap">
			<div id="icon-options-general" class="icon32"><br></div>
			<h2><?php _e( 'Consolidate Sites', 'site-consolidator' ); ?></h2>
			<p><?php _e( "You've come to this page for one reason.  You want to consolidate <em>some sites</em> into <strong>one site</strong>.", 'site-consolidator' ); ?></p>
			<p><?php _e( 'Not a problem.  Select your sites from the fields below on the left.  Select the single site you want to migrate them to on the right.  Hit "Consolidate".  Done.', 'site-consolidator' );?></p>
			<p><?php _e( 'What happens when you hit "Consolidate"?  A couple things:', 'site-consolidator' )?></p>
			<ol>
				<li><?php _e( 'All the posts, comments and images are migrated from the list of sites on the left to the site on the right.', 'site-consolidator' ); ?></li>
				<li><?php _e( 'Canonical rewrites are saved to the database for images and posts.', 'site-consolidator' ); ?></li>
				<li><?php _e( 'Proper attribution is given to authors.  If post authors do not exist on the site migrated to, that will be handled automatically by granting that author access to the new site.', 'site-consolidator' ); ?></li>
			</ol>

			<div id="site-consolidator-ui">
				<div id="all-sites">
					<strong><?php _e( 'Consolidate these...' ); ?></strong>
					<select multiple name="sites-to-consolidate[]">
						<?php 
							foreach ( self::get_sites() as $site )
								echo '<option value="' . $site->blog_id . '">' . $site->domain . '</option>';
						?>
					</select>
				</div>
				<div id="one-site-to-rule-them-all">
					<strong><?php _e( '...into this', 'site-consolidator' ); ?></strong>
					<select name="catcher-site">
						<?php 
							foreach ( self::get_sites() as $site )
								echo '<option value="' . $site->blog_id . '">' . $site->domain . '</option>';
						?>
					</select>
				</div>
			</div>

		</div>
		<div class="clear"></div>
		<?php wp_nonce_field( 'consolidator-nonce','consolidator-nonce-field' ); ?>
		<?php submit_button( __( 'Consolidate', 'site-consolidator' ) ); ?> 
		</form>
		<?php
	}

	/**
	 * Sets some general spacing for the select boxes on the 'Consolidate Sites' UI
	 * Trying to keep this mu-plugins safe, which is why we're not doing this in external files and enqueing
	 * 
	 * @return css
	 */
	public static function print_styles() {
		?>
		<style type='text/css'>
			#site-consolidator-ui select[multiple] {
				display: block;
				padding: 6px;
				margin: 8px 0;
				height: 340px;
			}

			#site-consolidator-ui div {
				float: left;
			}

			#site-consolidator-ui #one-site-to-rule-them-all {		
				margin-top: 23px;
				margin-left: 10px;
			}
		</style>
		<?php
	}

	/**
	 * Prints out javascript in footer to ensure that users are alerted when they try to consolidate a site into itself
	 * 
	 * Could (read: should) be more DRY, this was a quick first run.
	 * 
	 * @uses get_current_screen()
	 * @return javascript
	 */
	public static function print_scripts() {

		if ( 'admin_page_site-consolidator-network' != get_current_screen()->base )
			return;

		//Could probably use wp_localize_script() - but given that I'm building this for mu-plugins, no external enqueued scripts - this should suffice.
		$hey_dont_consolidate_yourself = __( 'Hey, you cannot consolidate a site into itself.', 'site-consolidator' );

		?>

		<script type='text/javascript'>
		jQuery(document).ready(function($) {

			var auto_consolidate_error = '<?php echo esc_js( $hey_dont_consolidate_yourself ); ?>';

			$('#site-consolidator-ui select:eq(0)').change(function(){
				var comp = $('#site-consolidator-ui select:eq(1)').val();

				if ( -1 !== $.inArray( comp, $(this).val() ) ) {
					alert(auto_consolidate_error);
				}

			});

			$('#site-consolidator-ui select:eq(1)').change(function(){

				var comp = $('#site-consolidator-ui select:eq(0)').val();

				if ( -1 !== $.inArray( $(this).val(), comp ) ) {
					alert(auto_consolidate_error);
				}


			});

		});
		</script>

		<?php
	}

	/**
	 * Returns all sites in network and saves as a transient.
	 * Returns an empty array if it is a large network - this UI (and really, this entire plugin) would simply not scale at that level.
	 * 
	 * @return array
	 */
	public static function get_sites() {
		global $wpdb;

		//This could (and should) be changed for any public release.  Current use case doesn't even approach a large network - but in case this were running haphazardly on a large network, this check exists.
		if ( wp_is_large_network() )
			return array();

		if ( false === ( $sites = get_transient( 'consolidate_sites_list' ) ) ) {
			$sites = $wpdb->get_results( $wpdb->prepare( "SELECT domain, blog_id FROM {$wpdb->blogs} WHERE site_id = '{$wpdb->siteid}'" ) );
			set_transient( 'consolidate_sites_list', $sites, ( 60 * 60 * 24 * 7 ) );
		}

		return $sites;
	}

	/**
	 * The primary wrapper for all of the crazy shenanigans.
	 * 
	 * Runs on $_POST on our network page. Runs some sanity checks, sets an egregious time limit, suspends cache addition and invalidation and does the migration.
	 * 
	 * @return void
	 */
	public static function process_consolidation() {

		//Sanity checks.  
		if ( 'admin_page_site-consolidator-network' != get_current_screen()->base || empty( $_POST ) )
			return;

		check_admin_referer( 'consolidator-nonce', 'consolidator-nonce-field' );

		$old_ids   = array_filter( $_POST['sites-to-consolidate'], 'absint' );
		$new_id    = absint( $_POST['catcher-site'] );
		$key_check = array_search( $new_id, $old_ids );

		if ( false !== $key_check )
			unset( $old_ids[$key_check] );

		//More or less arbitrary - but the HTTP Taxonomy API is going to take quite a bit of time.
		set_time_limit( 250 );

		wp_suspend_cache_addition( true );
		wp_suspend_cache_invalidation( true );

		foreach ( $old_ids as $blog_id ) {
			self::$_posts = self::$_tax_object = self::$_old_new_relationship = self::$_old_new_comments = array();
			self::migrate_posts( $blog_id, $new_id );
			self::migrate_authors( $blog_id, $new_id );
			self::migrate_comments( $blog_id, $new_id ); //Not quite ready for prime time
			self::reassign_post_meta( $blog_id, $new_id );
			self::migrate_attachments( $blog_id, $new_id );
			self::add_canonical_redirects( $blog_id, $new_id );

			//Easiest way to flush rewrite rules on a blog we're not on.
			delete_blog_option( $blog_id, 'rewrite_rules' );
		}

		delete_blog_option( $new_id, 'rewrite_rules' );
		wp_suspend_cache_addition( false );
		wp_suspend_cache_invalidation( false );
	}

	/**
	 * Handles post migration from old blog to new blog
	 * 
	 * Switches to old blog, grabs all posts, child posts and post meta, then switches to new blog and inserts them.
	 * Restores to current blog at the end.
	 * 
	 * Also, quite inelegantly, handles term/taxonomy migration, including hierarchical term relationships.
	 * There are almost certainly better ways to do this.
	 * 
	 * Regarding use of restore_current_blog() - not actually sure that's necessary, but it's in here anyway.  
	 * Naturally, always necessary if on an actual blog, but this entire thing is run within Network Admin. 
	 * 
	 * @param int $blog_id 
	 * @param int $new_id 
	 * @return     void
	 */
	private static function migrate_posts( $old_blog_id, $new_blog_id ) {
		
		switch_to_blog( $old_blog_id );

		$old_posts = get_posts( array( 'numberposts' => -1, 'post_type' => 'any', 'post_parent' => '0', 'post_status' => 'any' ) );

		$tax_api = new WP_JSON_Taxonomy_API( $old_blog_id );

		//Rather than grabbing taxonomies in each post, we can build an array of [tax_slug] => [terms_slug] => object_ids to use with the migration.  I think.
		foreach ( $tax_api->get_taxonomies() as $tax ) {
			$terms = $tax_api->get_terms( $tax, array( 'hide_empty' => false ) );
			if ( ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					self::$_tax_object[$tax][$term->slug] = $tax_api->get_objects_in_term( $term->term_id, $tax );
					if ( $term->parent )
						self::$_tax_object[$tax][$term->slug]['parent'] = $tax_api->get_term_by( 'id', $term->parent, $tax )->slug;
				}
			}
		}
		//Builds array of posts, child posts and post meta (for each)
		foreach ( $old_posts as $post ) {

			$post = (array) $post;

			//This is necessary - having a post ID in the post object when we wp_insert_post() later would attempt to update said post.  Peril.
			$old_id = $post['ID'];
			unset ( $post['ID'] );
			self::$_posts[$old_id]         = $post;
			self::$_posts[$old_id]['meta'] = get_post_custom( $old_id );

			//Here, we're running a check for children posts.  We'll make them a child array for the import process to maintain the parent relationship
			$children = self::has_children( $old_id );

			if ( ! empty( $children ) ) {
				foreach ( $children as $child_post ) {

					$child_post = (array) $child_post;
					
					$old_child_id = $child_post['ID'] ;
					unset( $child_post['ID'] );
					self::$_posts[$old_id]['children'][$old_child_id]         = $child_post;
					self::$_posts[$old_id]['children'][$old_child_id]['meta'] = get_post_custom( $old_child_id );
				}
			}
		}

		//Switches to new blog, inserts data, restores.
		restore_current_blog();
		switch_to_blog( $new_blog_id );

		foreach( self::$_posts as $post_id => $post ) {

			//Inserts 'parent' posts, related post meta and related terms.
			$parent_id = wp_insert_post( $post );
			self::$_old_new_relationship[$post_id] = $parent_id;

			if ( ! empty( $post['meta'] ) ) {
				foreach( $post['meta'] as $key => $value ) {
					//Need to loop through the values, in case there are multiple values
					foreach ( $value as $unique_value ) {
						add_post_meta( $parent_id, $key, maybe_unserialize( $unique_value ) );
					}
				}
			}

			if ( ! empty( $post['children'] ) ) {

				foreach( $post['children'] as $child_post_id => $child_post ) {
					$child_post['post_parent'] = $parent_id;
					$new_child_id = wp_insert_post( $child_post );
					self::$_old_new_relationship[$child_post_id] = $new_child_id;

					if ( ! empty( $child_post['meta'] ) ) {
						foreach( $child_post['meta'] as $key => $value ) {
							//Need to loop through the values, in case there are multiple values
							foreach ( $value as $unique_value ) {
								add_post_meta( $new_child_id, $key, maybe_unserialize( $unique_value ) );
							}
						}
					}
				}
			}
		}

		$new_tax_api = new WP_JSON_Taxonomy_API( $new_blog_id );

		//Inserts terms, if the taxonomy exists and the term doesn't
		foreach ( self::$_tax_object as $tax => $terms ) {

			//If the taxonomy doesn't exist, we're not going to do the hackery necessary to register taxonomies via saved options and work-around activation routines, etc.
			if ( $new_tax_api->taxonomy_exists( $tax ) ) {

				foreach( $terms as $term => $objects_in_term ) {
					if ( isset( self::$_tax_object[$tax][$term]['parent'] ) )
						$parent = self::$_tax_object[$tax][$term]['parent'];
					else 
						$parent = false;
					//If the term already exists, we're not going to override it.  That'd just be silly.
					if ( ! $new_tax_api->term_exists( $term, $tax ) ) {
						//This is a bit of a hacky way to ensure we've set up the parent first
						if ( $parent && ! $new_tax_api->term_exists( $parent, $tax ) ) {
							//Sets up parent term first.  We'll set up the child term outside the check.
							$name = $tax_api->get_term_by( 'slug', $parent, $tax )->name;
							$new_tax_api->wp_insert_term( $name, $tax );
						}

						$name = $tax_api->get_term_by( 'slug', $term, $tax )->name;
						//Now we insert the term.  We've already created the parent term if it didn't exist.  Now we just conditionally add a parent if we need to.
						$new_tax_api->wp_insert_term( $name, $tax, array( 'parent' => ( $parent ) ? $new_tax_api->get_term_by( 'slug', $parent, $tax )->term_id : 0 ) );
					}
				}
			}
		}

		//Create term / object relationships
		foreach ( self::$_tax_object as $tax => $terms ) {
			foreach( $terms as $term => $objects_in_term ) {
				foreach( $objects_in_term as $object_id ) {
					if ( isset( self::$_old_new_relationship[$object_id] ) )
						$new_tax_api->wp_set_object_terms( self::$_old_new_relationship[$object_id], $term, $tax, true );
				}
			}
		}

		restore_current_blog(); //To be honest - not sure if this is even necessary since we're running as a network tool
	}

	/**
	 * Grabs post authors from posts property, eliminates duplicates, adds to blog.
	 * 
	 * @param  int $new_id 
	 * @return void
	 */
	private static function migrate_authors( $blog_id, $new_id ) {

		$user_ids = array_unique( wp_filter_object_list( self::$_posts, array(), 'AND', 'post_author' ) );

		foreach ( $user_ids as $user_id ) {
			switch_to_blog( $blog_id );
				$roles = get_userdata( $user_id )->roles;
			restore_current_blog();

			switch_to_blog( $new_id );
				add_user_to_blog( $new_id, $user_id, $roles[0] );
			restore_current_blog();
		}
	
	}

	/**
	 * Recursive function 
	 * 
	 * @param type $old_id 
	 * @param type $new_id 
	 * @todo  Maybe handle comment meta.  I think the primary place core uses it is for trashing/spamming comments.  Might not be necessary
	 */
	private static function migrate_comments( $old_id, $new_id ) {
		
		switch_to_blog( $old_id );

		foreach ( self::$_old_new_relationship as $old_post_id => $new_post_id ) {
			$comments = get_comments( array( 'post_id' => $old_post_id ) );
			$comments[$new_post_id] = get_comments( array( 'post_id' => $old_post_id ) );

		}

		restore_current_blog();
		switch_to_blog( $new_id );

		//Need to add hierarchy here.
		foreach ( $comments as $post_id => $comments ) {
			foreach( $comments as $comment ) {
				$comment = (array) $comment;
				$comment['comment_post_ID'] = $post_id;
				self::$_old_new_comments[$comment['comment_ID']] = wp_insert_comment( $comment );
				if ( $comment['comment_parent'] )
					self::$_old_new_comments[$comment['comment_ID']]['parent'] = $comment['comment_parent'];
			}
		}

		foreach ( self::$_old_new_comments as $old_id => $new_id ) {
			if ( isset( self::$_old_new_comments[$old_id]['parent'] ) ) {
				wp_update_comment( array( 'comment_ID' => $new_id, 'comment_parent' => self::$_old_new_comments[self::$_old_new_comments[$old_id]['parent']] ) );
			}
		}

		restore_current_blog();
	}

	/**
	 * This could potentially be quite intensive.  If we have a bottleneck, this might be it.  Worth looking into handling on cron or a completely different approach.
	 * 
	 * @param  int $old_id 
	 * @param  int $new_id 
	 * @return void
	 */
	private static function migrate_attachments( $old_id, $new_id ) {

		//Database entries are moved in the migrate_posts method.  We're moving files here.  
		//References to the old files should redirect to the new files, handled in add_canonical_redirects()
		self::copy( WP_CONTENT_DIR . "/blogs.dir/{$old_id}/files/", WP_CONTENT_DIR . "/blogs.dir/{$new_id}/files/" );
	}

	/**
	 * Whelp.  Just realized we need to re-assign post meta ids.
	 * Specifically thinking for featured thumbnails, but potentially for others, too.
	 * 
	 * @param int $old_id 
	 * @param int $new_id 
	 */
	private static function reassign_post_meta( $old_id, $new_id ) {
		
		switch_to_blog( $new_id );
		
		foreach ( self::$_old_new_relationship as $old_post_id => $new_post_id )
			if ( false !== ( $old_thumb_id = get_post_meta( $new_post_id, '_thumbnail_id', true ) ) )
				update_post_meta( $new_post_id, '_thumbnail_id', self::$_old_new_relationship[$old_thumb_id] );

		restore_current_blog();
	}

	private static function add_canonical_redirects( $old_id, $new_id ) {
	}

	/**
	 * Recursively copies directory structure over.  Works well if directory already exists, or not.
	 * 
	 * @param string $source 
	 * @param string $dest 
	 */
	private static function copy( $source, $destination ) {
 
		if ( is_dir( $source ) ) {
			wp_mkdir_p( $destination );
			$directory = dir( $source );
			while ( false !== ( $readdirectory = $directory->read() ) ) {
				if ( '.' == $readdirectory || '..' == $readdirectory )
					continue;
				$pathdir = $source . '/' . $readdirectory; 
				if ( is_dir( $pathdir ) ) {
					self::copy( $pathdir, $destination . '/' . $readdirectory );
					continue;
				}
				copy( $pathdir, $destination . '/' . $readdirectory );
			}
			$directory->close();
		} else {
			copy( $source, $destination );
		}
	}

	/**
	 * Checks to see if a post has any children.
	 * 
	 * @param int $post_id 
	 * @return array Array of posts, or empty array if none.
	 */
	private static function has_children( $post_id ) {
		return get_posts( array( 'numberposts' => -1, 'post_type' => 'any', 'post_status' => 'any', 'post_parent' => absint( $post_id ) ) );
	}

}

/**
 * After much hackery and tomfoolery, I think this is probably as decent a solution as we'll get to ensure the taxonomy API
 * is relatively portable in a switch_to_blog() setting.
 * 
 * Will this scale? No. 
 * Is this hyper-performant? No.
 * Should it work marvelously for this specific use case?  You betcha.
 * 
 * This is essentially a wrapper function for the native WP Taxonomy API.  Sends requests.
 * 
 */
class WP_JSON_Taxonomy_API {

	/**
	 * The site URL for the blog ID provided
	 *
	 * @since 1.0
	 * @var string
	 * @access private
	 */
	private $_site_url;

	public function __construct( $blog_id ) {

		$this->_site_url = get_blog_details( $blog_id )->siteurl . '?json_api=true&function=';
		return $this;
	}

	public function get_taxonomies() {

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'get_taxonomies' ) ) );
	}

	public function get_terms( $taxonomies, $args = array() ) {
		$query = http_build_query( array( 
			'args' => array( 
				'taxonomies' => $taxonomies, 
				'args'       => $args 
				) 
			) 
		);

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'get_terms&' . $query ) ) );
	}

	public function get_objects_in_term( $term_ids, $taxonomies, $args = array() ) {
		$query = http_build_query( array( 
			'args' => array( 
				'term_ids'   => $term_ids, 
				'taxonomies' => $taxonomies, 
				'args'       => $args 
				) 
			) 
		);

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'get_objects_in_term&' . $query ) ) );
	}

	public function get_term_by( $field, $value, $taxonomy, $output = OBJECT, $filter = 'raw' ) {
		$query = http_build_query( array( 
			'args' => array( 
				'field'    => $field, 
				'value'    => $value, 
				'taxonomy' => $taxonomy,
				'output'   => $output,
				'filter'   => $filter
				) 
			) 
		);

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'get_term_by&' . $query ) ) );
	}

	public function taxonomy_exists( $taxonomy ) {
		$query = http_build_query( array( 
			'args' => array( 
				'taxonomy' => $taxonomy
				) 
			) 
		);

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'taxonomy_exists&' . $query ) ) );
	}

	public function term_exists( $term, $taxonomy, $parent = '' ) {
		$query = http_build_query( array( 
			'args' => array( 
				'term'     => $term, 
				'taxonomy' => $taxonomy, 
				'parent'   => $parent
				) 
			) 
		);
		$result = json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'term_exists&' . $query ) ) );
		
		return ! is_null( $result ) && 0 !== $result;
	}

	public function wp_insert_term( $term, $taxonomy, $args = array() ) {
		$query = http_build_query( array( 
			'args' => array( 
				'term'     => $term, 
				'taxonomy' => $taxonomy, 
				'args'     => $args
				) 
			) 
		);

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'wp_insert_term&' . $query ) ) );
	}

	public function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$query = http_build_query( array( 
			'args' => array( 
				'object_id' => $object_id, 
				'terms'     => $terms, 
				'taxonomy'  => $taxonomy,
				'append'    => $append
				) 
			) 
		);

		return json_decode( wp_remote_retrieve_body( wp_remote_get( $this->_site_url . 'wp_set_object_terms&' . $query ) ) );
	}

}

/**
 * 
 * This is essentially a wrapper function for the native WP Taxonomy API.  Receives requests.  Sends responses.
 * A bit of props to westi for the JSON endpoint code on trac
 * 
 * @package WP_JSON_Taxonomy_API
 */
class WP_JSON_Taxonomy_API_Handler {

	private static $instance;

	public function __construct() {
		add_action( 'init'             , array( $this, 'init' ) );
		add_action( 'request'          , array( $this, 'request' ) );
		add_action( 'template_redirect', array( $this, 'template_redirect' ) );
	}

	public function get_instance() {
		if ( empty( self::$instance ) )
			self::$instance = new WP_JSON_Taxonomy_API_Handler();

		return self::$instance;
	}

	public function init() {

		add_rewrite_endpoint( 'json_api', EP_ROOT ^ EP_PERMALINK ^ EP_ALL );
	}

	public function request( $query_vars ) {
		if ( isset( $query_vars['json_api'] ) )
			$query_vars['json_api'] = true;

		return $query_vars;
	}

	public function template_redirect() {
		if ( ! get_query_var( 'json_api' ) ) 
			return true;

 		$function = esc_attr( $_GET['function'] );
 
 		if ( ! function_exists( $function ) )
 			return true;

 		if ( ! isset( $_GET['args'] ) )
			die( json_encode( $function() ) );

		die( json_encode( call_user_func_array( $function, $_GET['args'] ) ) );
	}

}

add_action( 'plugins_loaded' , array( 'WP_Site_Consolidator'        , 'init' ) );
add_action( 'plugins_loaded' , array( 'WP_JSON_Taxonomy_API_Handler', 'get_instance' ) );

?>