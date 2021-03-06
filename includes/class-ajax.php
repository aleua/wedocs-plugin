<?php

/**
 * Ajax Class
 */
class WeDocs_Ajax {

    /**
     * Bind actions
     */
    function __construct() {
        add_action( 'wp_ajax_wedocs_create_doc', array( $this, 'create_doc' ) );
        add_action( 'wp_ajax_wedocs_remove_doc', array( $this, 'remove_doc' ) );
        add_action( 'wp_ajax_wedocs_admin_get_docs', array( $this, 'get_docs' ) );
        add_action( 'wp_ajax_wedocs_sortable_docs', array( $this, 'sort_docs' ) );

	    /**
	     * Load FAQ data.
	     *
	     * @author Vova Feldman
	     */
	    add_action( 'wp_ajax_wedocs_create_category', array( $this, 'create_category' ) );
	    add_action( 'wp_ajax_wedocs_remove_category', array( $this, 'remove_category' ) );
	    add_action( 'wp_ajax_wedocs_create_faq_doc', array( $this, 'create_faq_doc' ) );
	    add_action( 'wp_ajax_wedocs_admin_get_faq', array( $this, 'get_faq' ) );
	    add_action( 'wp_ajax_wedocs_sortable_faq', array( $this, 'sort_faq' ) );
	    add_action( 'wp_ajax_wedocs_sortable_category', array( $this, 'sort_category' ) );
	    add_action( 'wp_ajax_wedocs_toggle_doc_faq', array( $this, 'toggle_doc_faq' ) );
	    add_action( 'wp_ajax_wedocs_toggle_doc_visibility', array( $this, 'toggle_doc_visibility' ) );

	    add_action( 'wp_ajax_wedocs_rated', array( $this, 'hide_wedocs_rating' ) );

        add_action( 'wp_ajax_wedocs_ajax_feedback', array( $this, 'handle_feedback' ) );
        add_action( 'wp_ajax_nopriv_wedocs_ajax_feedback', array( $this, 'handle_feedback' ) );
    }

	/**
	 * Added helper method for the post creation.
	 *
	 * @author Vova Feldman
	 *
	 * @return array
	 */
	private function create_doc_post() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$title  = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : 'draft';
		$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		$order  = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;

		$post_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_type'   => 'docs',
			'post_status' => $status,
			'post_parent' => $parent,
			'post_author' => get_current_user_id(),
			'menu_order'  => $order
		) );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error();
		}

		return array(
			'id'     => $post_id,
			'title'  => $title,
			'status' => $status,
			'is_faq' => false,
		);
	}

    /**
     * Create a new doc
     *
     * @return void
     */
    public function create_doc() {
        $post = $this->create_doc_post();

        wp_send_json_success( array(
            'post' => $post,
            'child' => array()
        ) );
    }

    /**
     * Delete a doc
     *
     * @return void
     */
    public function remove_doc() {
        check_ajax_referer( 'wedocs-admin-nonce' );

        $force_delete = false;
        $post_id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( $post_id ) {
            // delete childrens first if found
            $this->remove_child_docs( $post_id, $force_delete );

            // delete main doc
            wp_delete_post( $post_id, $force_delete );
        }

        wp_send_json_success();
    }

    /**
     * Remove child docs
     *
     * @param  integer  $parent_id
     *
     * @return void
     */
    public function remove_child_docs( $parent_id = 0, $force_delete ) {
        $childrens = get_children( array( 'post_parent' => $parent_id ) );

        if ( $childrens ) {
            foreach ($childrens as $child_post) {
                // recursively delete
                $this->remove_child_docs( $child_post->ID, $force_delete );

                wp_delete_post( $child_post->ID, $force_delete );
            }
        }
    }

    /**
     * Get all docs
     *
     * @return void
     */
    public function get_docs() {
        check_ajax_referer( 'wedocs-admin-nonce' );

        $docs = get_pages( array(
            'post_type'      => 'docs',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => '-1',
            'orderby'        => 'menu_order',
            'order'          => 'ASC'
        ) );

	    // Remove all FAQ without a parent.
	    foreach ($docs as $index => $doc) {
		    /**
		     * @var WP_Post $doc
		     */
		    if ( empty( $doc->post_parent ) && has_term( 'faq', 'doc_tag', $doc->ID ) ) {
			    unset( $docs[ $index ] );
		    }
	    }

        $arranged = $this->build_tree( $docs );
        usort( $arranged, array( $this, 'sort_callback' ) );
        wp_send_json_success( $arranged );
    }

    /**
     * Assume the user rated weDocs
     *
     * @return void
     */
    public function hide_wedocs_rating() {
        check_ajax_referer( 'wedocs-admin-nonce' );

        update_option( 'wedocs_admin_footer_text_rated', 'yes' );
        wp_send_json_success();
    }

    /**
     * Store feedback for an article
     *
     * @return void
     */
    function handle_feedback() {
        check_ajax_referer( 'wedocs-ajax' );

        $template = '<div class="wedocs-alert wedocs-alert-%s">%s</div>';
        $previous = isset( $_COOKIE['wedocs_response'] ) ? explode( ',', $_COOKIE['wedocs_response'] ) : array();
        $post_id  = intval( $_POST['post_id'] );
        $type     = in_array( $_POST['type'], array( 'positive', 'negative' ) ) ? $_POST['type'] : false;

        // check previous response
        if ( in_array( $post_id, $previous ) ) {
            $message = sprintf( $template, 'danger', __( 'Sorry, you\'ve already recorded your feedback!', 'wedocs' ) );
            wp_send_json_error( $message );
        }

        // seems new
        if ( $type ) {
            $count = (int) get_post_meta( $post_id, $type, true );
            update_post_meta( $post_id, $type, $count + 1 );

            array_push( $previous, $post_id );
            $cookie_val = implode( ',',  $previous);

            $val = setcookie( 'wedocs_response', $cookie_val, time() + WEEK_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
        }

        $message = sprintf( $template, 'success', __( 'Thanks for your feedback!', 'wedocs' ) );
        wp_send_json_success( $message );
    }

    /**
     * Sort docs
     *
     * @return void
     */
    public function sort_docs() {
        check_ajax_referer( 'wedocs-admin-nonce' );

        $doc_ids = isset( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();

        if ( $doc_ids ) {
            foreach ($doc_ids as $order => $id) {
                wp_update_post( array(
                    'ID'         => $id,
                    'menu_order' => $order
                ) );
            }
        }

        exit;
    }

    /**
     * Build a tree of docs with parent-child relation
     *
     * @param  array   $docs
     * @param  integer  $parent
     *
     * @return array
     */
    public function build_tree( $docs, $parent = 0 ) {
        $result = array();

        if ( ! $docs ) {
            return $result;
        }

        foreach ($docs as $key => $doc) {
            if ( $doc->post_parent == $parent ) {
                unset( $docs[ $key ] );

                // build tree and sort
                $child = $this->build_tree( $docs, $doc->ID );
                usort( $child, array( $this, 'sort_callback' ) );

                $result[] = array(
                    'post' => array(
                        'id'     => $doc->ID,
                        'title'  => $doc->post_title,
                        'status' => $doc->post_status,
                        'order'  => $doc->menu_order,
	                    'is_faq' => has_term( 'faq', 'doc_tag', $doc->ID ),
                    ),
                    'child' => $child
                );
            }
        }

        return $result;
    }

    /**
     * Sort callback for sorting posts with their menu order
     *
     * @param  array  $a
     * @param  array  $b
     *
     * @return int
     */
    public function sort_callback( $a, $b ) {
        return $a['post']['order'] - $b['post']['order'];
    }

	/**
	 * Toggle doc/article visibility.
	 *
	 * @author Vova Feldman
	 */
	public function toggle_doc_visibility() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$status = 'publish';

		if ( $post_id ) {
			$status = get_post_status( $post_id );

			$status = ( 'publish' === $status ) ?
				'draft' :
				'publish';

			wp_update_post( array(
				'ID'     => $post_id,
				'post_status' => $status,
			) );
		}

		// Assumes term addition/removal doesn't fail.
		wp_send_json_success( array(
			'status' => $status,
		) );
	}

	#--------------------------------------------------------------------------
	#region FAQ
	#--------------------------------------------------------------------------

	/**
	 * Toggle article FAQ tag.
	 *
	 * @author Vova Feldman
	 */
	public function toggle_doc_faq() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$has_term = false;

		if ( $post_id ) {
			$category_all    = get_term_by( 'slug', 'all', 'doc_category' );
			$category_all_id = $category_all->term_id;

			if ( has_term( 'faq', 'doc_tag', $post_id ) ) {
				// Remove faq tag.
				wp_remove_object_terms( $post_id, 'faq', 'doc_tag' );

				// Remove from All FAQ category.
				wp_remove_object_terms( $post_id, $category_all_id, 'doc_category' );

				$has_term = false;
			} else {
				// Add faq tag.
				wp_add_object_terms( $post_id, 'faq', 'doc_tag' );

				// Add to All FAQ category.
				wp_set_object_terms( $post_id, $category_all_id, 'doc_category', true );

				$order = $this->get_faq_doc_order( $post_id );

				// If added to FAQ for the 1st time, add as the last in All FAQ category.
				if ( ! isset( $order["id_{$category_all_id}"] ) ) {
					$category_posts = get_posts( array(
						'post_type'      => 'docs',
						'post_status'    => array( 'publish', 'draft' ),
						'exclude'        => array($post_id),
						'posts_per_page' => '-1',
						'tax_query'      => array(
							array(
								'taxonomy' => 'doc_tag',
								'field'    => 'slug',
								'terms'    => 'faq'
							),
							array(
								'taxonomy' => 'doc_category',
								'field'    => 'term_id',
								'terms'    => $category_all_id
							),
						),
					) );

					$max_order = 1;
					foreach ( $category_posts as $p ) {
						$p_order = $this->get_faq_doc_order( $p->ID );

						if ( !empty( $p_order["id_{$category_all_id}"] ) ) {
							$max_order = max( $max_order, $p_order["id_{$category_all_id}"] );
						}
					}

					$order["id_{$category_all_id}"] = $max_order + 1;
					update_post_meta( $post_id, 'faq-order', $order );
				}

				$has_term = true;
			}
		}

		// Assumes term addition/removal doesn't fail.
		wp_send_json_success( array(
			'is_faq' => $has_term,
		) );
	}

	private function get_faq_doc_order($post_id){
		$order = get_post_meta( $post_id, 'faq-order', true );

		if ( empty( $order ) ) {
			$order = array();
		}

		return $order;
	}

	/**
	 * Create a new category.
	 *
	 * @author Vova Feldman
	 *
	 * @return void
	 */
	public function create_category() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$title = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
		$order = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;

		$category_id = wp_insert_category( array(
			'taxonomy' => 'doc_category',
			'cat_name' => $title,
		) );

		if ( is_wp_error( $category_id ) ) {
			wp_send_json_error();
		}

		update_term_meta( $category_id, 'faq-order', $order );

		wp_send_json_success( array(
			'category' => array(
				'id'    => $category_id,
				'title' => $title,
				'order' => $order,
			),
			'child'    => array()
		) );
	}

	/**
	 * Remove specified category.
	 *
	 * @author Vova Feldman
	 *
	 * @return void
	 */
	public function remove_category() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$category_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( $category_id ) {
			// delete main doc
			wp_delete_term( $category_id, 'doc_category' );
		}

		wp_send_json_success();
	}

	/**
	 * Create a new FAQ doc
	 *
	 * @author Vova Feldman
	 *
	 * @return void
	 *
	 * @uses create_doc_post()
	 */
	public function create_faq_doc() {
		// No need to check referer, create_doc_post() already does it.

		$post = $this->create_doc_post();

		$post_id = $post['id'];

		// Associate with FAQ tag.
		wp_add_object_terms( $post_id, 'faq', 'doc_tag' );

		// Add to All FAQ category.
		wp_set_object_terms( $post_id, 'all', 'doc_category', true );


		$category_id        = isset( $_POST['category_id'] ) ? absint($_POST['category_id']) : false;
		$category_order     = isset( $_POST['category_order'] ) ? absint($_POST['category_order']) : false;
		$category_all_order = isset( $_POST['category_all_order'] ) ? absint($_POST['category_all_order']) : false;

		$order      = $this->get_faq_doc_order( $post_id );
		$is_changed = false;

		if ( is_numeric( $category_id ) ) {
			// Add to specified FAQ category.
			wp_set_object_terms( $post_id, $category_id, 'doc_category', true );

			if ( is_numeric( $category_order ) ) {
				$order["id_{$category_id}"] = $category_order;
				$is_changed                 = true;
			}
		}

		if ( is_numeric( $category_all_order ) ) {
			$category_all = get_term_by( 'slug', 'all', 'doc_category' );

			$order["id_{$category_all->term_id}"] = $category_all_order;
			$is_changed                           = true;
		}

		if ( $is_changed ) {
			update_post_meta( $post_id, 'faq-order', $order );
		}

		$post['is_faq'] = true;

		wp_send_json_success( array(
			'post'  => $post,
			'child' => array()
		) );
	}

	/**
	 * Get all docs with the `faq` tag.
	 *
	 * @return void
	 */
	public function get_faq() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		wp_send_json_success( wedocs_get_faq_tree() );
	}

	/**
	 * Sort FAQ
	 *
	 * @author Vova Feldman
	 *
	 * @return void
	 */
	public function sort_faq() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$faq_ids     = isset( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
		$category_id = isset( $_POST['category_id'] ) ? $_POST['category_id'] : false;

		if ( ! is_numeric( $category_id ) ) {
			exit;
		}

		if ( $faq_ids ) {
			foreach ( $faq_ids as $category_order => $id ) {
				$order = get_post_meta( $id, 'faq-order', true );

				if ( empty( $order ) ) {
					$order = array();
				}

				$order[ "id_{$category_id}" ] = $category_order;

				update_post_meta( $id, 'faq-order', $order );
			}
		}

		exit;
	}

	/**
	 * Sort FAQ category.
	 *
	 * @author Vova Feldman
	 *
	 * @return void
	 */
	public function sort_category() {
		check_ajax_referer( 'wedocs-admin-nonce' );

		$category_ids = isset( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();

		if ( $category_ids ) {
			foreach ($category_ids as $order => $id) {
				update_term_meta( $id, 'faq-order', $order );
			}
		}

		exit;
	}

	#endregion
}

new WeDocs_Ajax();
