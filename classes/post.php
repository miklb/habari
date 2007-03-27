<?php

define('SLUG_POSTFIX', '-');

/**
 * @package Habari
 *
 * Includes an instance of the PostInfo class; for holding inforecords about a Post
 * If the Post object describes an existing post; use the internal info object to
 * get, set, unset and test for existence (isset) of info records.
 * <code>
 * $this->info = new PostInfo ( 1 );  // Info records of post with id = 1
 * $this->info->option1= "blah"; // set info record with name "option1" to value "blah"
 * $info_value= $this->info->option1; // get value of info record with name "option1" into variable $info_value
 * if ( isset ($this->info->option1) )  // test for existence of "option1"
 * unset ( $this->info->option1 ); // delete "option1" info record
 * </code>
 *
 */
class Post extends QueryRecord
{
	// static variables to hold post status and post type values
	static $post_status_list= array();
	static $post_type_list= array();

	private $tags= null;
	private $comments_object= null;
	private $author_object= null;
		
	private $info= null;

	/**
	 * returns an associative array of post types
	 * @param bool whether to force a refresh of the cached values
	 * @return array An array of post type names => integer values
	**/
	public static function list_post_types( $refresh = false )
	{
		if ( ( ! $refresh ) && ( ! empty( self::$post_type_list ) ) )
		{
			return self::$post_type_list;
		}
		self::$post_type_list['any']= 0;
		$sql= 'SELECT * FROM ' . DB::table('posttype') . ' ORDER BY id ASC';
		$results= DB::get_results( $sql );
		foreach ($results as $result)
		{
			self::$post_type_list[$result->name]= $result->id;
		}
		return self::$post_type_list;
	}

	/**
	 * returns an associative array of post statuses
	 * @param bool whether to force a refresh of the cached values
	 * @return array An array of post statuses names => interger values
	**/
	public static function list_post_statuses( $refresh= false )
	{
		if ( ( ! $refresh ) && ( ! empty( self::$post_status_list ) ) )
		{
			return self::$post_status_list;
		}
		self::$post_status_list['any']= 0;
		$sql= 'SELECT * FROM ' . DB::table('poststatus') . ' ORDER BY id ASC';
		$results= DB::get_results( $sql );
		foreach ($results as $result)
		{
			self::$post_status_list[$result->name]= $result->id;
		}
		return self::$post_status_list;
	}

	/**
	 * returns the interger value of the specified post status, or false
	 * @param string a post status name
	 * @return mixed an integer or boolean false
	**/
	public static function status( $name )
	{
		$statuses= Post::list_post_statuses();
		if ( isset( $statuses[strtolower($name)] ) )
		{
			return $statuses[strtolower($name)];
		}
		return false;
	}

	/**
	 * returns the integer value of the specified post type, or false
	 * @param string a post type
	 * @return mixed an integer or boolean false
	**/
	public static function type( $name )
	{
		$types= Post::list_post_types();
		if ( in_array( strtolower($name), $types ) )
		{
			return $types[strtolower($name)];
		}
		return false;
	}
 
	/**
	 * Return the defined database columns for a Post.
	 * @return array Array of columns in the Post table
	**/
	public static function default_fields()
	{
		return array(
			'id' => 0,
			'slug' => '',
			'title' => '',
			'guid' => '',
			'content' => '',
			'cached_content' => '',
			'user_id' => 0,
			'status' => Post::status('draft'),
			'pubdate' => date( 'Y-m-d H:i:s' ),
			'updated' => date ( 'Y-m-d H:i:s' ),
			'content_type' => Post::type('entry')
		);
	}

	/**
	 * Constructor for the Post class.
	 * @param array $paramarray an associative array of initial Post field values.
	 **/	 	 	 	
	public function __construct( $paramarray = array() )
	{
		// Defaults
		$this->fields = array_merge(
			self::default_fields(),
			$this->fields );
		
		parent::__construct( $paramarray );
		if ( isset( $this->fields['tags'] ) )
		{
			$this->tags = $this->parsetags($this->fields['tags']);
			unset( $this->fields['tags'] );
		}
		$this->exclude_fields('id');
		$this->info= new PostInfo ( $this->fields['id'] );
		 /* $this->fields['id'] could be null in case of a new post. If so, the info object is _not_ safe to use till after set_key has been called. Info records can be set immediately in any other case. */
	}
	
	/**
	 * static function get
	 * Returns a single requested post
	 *
	 * <code>
	 * $post = Post::get( array( 'slug' => 'wooga' ) );
	 * </code>
	 *
	 * @param array An associated array of parameters, or a querystring
	 * @return array A single Post object, the first if multiple results match
	 **/	 	 	 	 	
	static function get( $paramarray = array() )
	{
		// Defaults
		$defaults= array (
			'where' => array(
				array(
					'status' => Post::status('published'),
				),
			),
			'fetch_fn' => 'get_row',
		);
		if ( $user = User::identify() ) {
			$defaults['where'][]= array(
				'user_id' => $user->id,
			);
		}
		foreach($defaults['where'] as $index => $where)
		{
			$defaults['where'][$index]= array_merge(Controller::get_handler()->handler_vars, $where, Utils::get_params($paramarray));
		}
		 
		return Posts::get( $defaults );
	}
	
	/**
	 * static function create
	 * Creates a post and saves it
	 * @param array An associative array of post fields
	 * $return Post The post object that was created	 
	 **/	 	 	
	static function create($paramarray) 
	{
		$post = new Post($paramarray);
		$post->insert();
		return $post;
	}

	/**
	 * New slug setter.  Using different function name to test an alternate
	 * algorithm.
	 *
	 * The method both sets the internal slug and returns the 
	 * generated slug.
	 *
	 * @return  string  Generated slug
	 */
	private function set_slug() {
		/* 
		 * Do we already have a slug in for the post?
		 * If so, double check we haven't changed the slug
		 * manually by setting newfields['slug']
		 */
		$old_slug= strtolower($this->fields['slug']);
		$new_slug= strtolower((isset($this->newfields['slug']) ? $this->newfields['slug'] : ''));

		if (! empty($old_slug)) {
			if ($old_slug == $new_slug)
				return $new_slug;
		}
	
		/* 
		 * OK, we have a new slug or no slug at all
		 * For either case, we need to double check 
		 * that the slug doesn't already exist for another
		 * post in the DB.  But first, we must create
		 * a new slug if there isn't one set manually.
		 */
		if (empty($new_slug)) {
			/* Create a new slug from title */
			$title= strtolower((isset($this->newfields['title']) ? $this->newfields['title'] : $this->fields['title']));
			$new_slug= preg_replace('/[^a-z0-9]+/i', SLUG_POSTFIX, $title);
			$new_slug= rtrim($new_slug, SLUG_POSTFIX);
		}

		/*
		 * Check for an existing post with the same slug.
		 * To do so, we cut off any postfixes from the new slug
		 * and check the DB for the slug without postfixes
		 */
		$check_slug= rtrim($new_slug, SLUG_POSTFIX);
		$sql= "SELECT COUNT(*) as slug_count FROM " . DB::table('posts') . " WHERE slug LIKE '" . $check_slug . "%';";
		$num_posts= DB::get_value($sql);
		$valid_slug= $check_slug . str_repeat(SLUG_POSTFIX, $num_posts);
		$this->newfields['slug']= $valid_slug;
		return $valid_slug;
	}
	
	/**
	 * Attempts to generate the slug for a post that has none
	 * @return The slug value	 
	 */	 	 	 	 	
	private function setslug()
	{
		if ( $this->fields[ 'slug' ] != '' && $this->fields[ 'slug' ] == $this->newfields[ 'slug' ]) {
			$value= $this->fields[ 'slug' ];
		}
		elseif ( isset( $this->newfields['slug']) && $this->newfields[ 'slug' ] != '' ) {
			$value= $this->newfields[ 'slug' ];
		}
		elseif ( ( $this->fields[ 'slug' ] != '' ) ) {
			$value= $this->fields[ 'slug' ];
		}
		elseif ( isset( $this->newfields['title'] ) && $this->newfields[ 'title' ] != '' ) {
			$value= $this->newfields[ 'title' ];
		}
		elseif ( $this->fields[ 'title' ] != '' ) {
			$value= $this->fields[ 'title' ];
		}
		else {
			$value= 'Post';
		}
		
		$slug= strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $value ) );
		$postfix= '';
		$postfixcount= 0;
		do {
			if (! $slugcount = DB::get_row( 'SELECT count(slug) AS ct FROM ' . DB::table('posts') . ' WHERE slug = ?;', array( "{$slug}{$postfix}" ) )) {
				Utils::debug(DB::get_errors());
				exit;
			}
			if ( $slugcount->ct != 0 ) $postfix = "-" . ( ++$postfixcount );
		} while ($slugcount->ct != 0);
		$this->newfields[ 'slug' ] = $slug . $postfix;
		return $this->newfields[ 'slug' ];
	}

	/**
	 * function setguid
	 * Creates the GUID for the new post
	 */	 	 	 	 	
	private function setguid()
	{
		if ( ! isset( $this->newfields['guid'] ) 
			|| ($this->newfields['guid'] == '')  // GUID is empty 
			|| ($this->newfields['guid'] == '//?p=') // GUID created by WP was erroneous (as is too common)
		) {
			$result= 'tag:' . Site::get_url('hostname') . ',' . date('Y') . ':' . $this->setslug() . '/' . time();
			$this->newfields['guid']= $result;
		}
		return $this->newfields['guid'];
	}
	
	/**
	 * function setstatus
	 * @param mixed the status to set it to. String or integer.
	 * @return integer the status of the post
	 * Sets the status for a post, given a string or integer.
	 */	 	 	 	 	
	private function setstatus($value)
	{
		$statuses= Post::list_post_statuses();
		if( is_numeric($value) && in_array($value, $statuses) )
		{
			$this->newfields['status'] = $value;
		}
		elseif ( array_key_exists( $value, $statuses ) )
		{
			$this->newfields['status'] = Post::status('publish');
		}
		return $this->newfields['status'];
	}

	private function parsetags( $tags )
	{
		if ( is_string( $tags ) ) {
			// dirrty ;)
			$rez= array( '\\"'=>':__unlikely_quote__:', '\\\''=>':__unlikely_apos__:' );
			$zer= array( ':__unlikely_quote__:'=>'"', ':__unlikely_apos__:'=>"'" );
			// escape
			$tagstr= str_replace( array_keys( $rez ), $rez, $tags );
			// match-o-matic
			preg_match_all( '/((("|((?<= )|^)\')\\S([^\\3]*?)\\3((?=[\\W])|$))|[^,])+/', $tagstr, $matches );
			// cleanup
			$tags= array_map( 'trim', $matches[0] );
			$tags= preg_replace( array_fill( 0, count( $tags ), '/^(["\'])(((?!").)+)(\\1)$/'), '$2', $tags );
			// unescape
			$tags= str_replace( array_keys( $zer ), $zer, $tags );
			// hooray
			return $tags;
		}
		elseif ( is_array( $tags ) ) {
			return $tags;
		}
	}

	/**
	 * Save the tags associated to this post into the tags and tags2post tables
	 */	 	
	private function savetags()
	{
		if ( count($this->tags) == 0) {return;}
		DB::query( 'DELETE FROM ' . DB::table('tag2post') . ' WHERE post_id = ?', array( $this->fields['id'] ) );
		foreach( (array)$this->tags as $tag ) { 
			// @todo TODO Make this multi-SQL safe!
			if( DB::get_value( 'SELECT count(*) FROM ' . DB::table('tags') . ' WHERE tag_text = ?', array( $tag ) ) == 0 ) {
				DB::query( 'INSERT INTO ' . DB::table('tags') . ' (tag_text, tag_slug) VALUES (?, ?)', array( $tag, $tag ) );
			}
			DB::query( 'INSERT INTO ' . DB::table('tag2post') . ' (tag_id, post_id) SELECT id AS tag_id, ? AS post_id FROM ' . DB::table('tags') . ' WHERE tag_text = ?', 
				array( $this->fields['id'], $tag ) 
			); 
		}
	}
	
	/**
	 * function insert
	 * Saves a new post to the posts table
	 */	 	 	 	 	
	public function insert()
	{
		$this->newfields[ 'updated' ] = date( 'Y-m-d h:i:s' );
		$this->setslug();
		$this->setguid();
		$result = parent::insert( DB::table('posts') );
		$this->newfields['id'] = DB::last_insert_id(); // Make sure the id is set in the post object to match the row id
		$this->fields = array_merge($this->fields, $this->newfields);
		$this->newfields = array();
		$this->info->commit( DB::last_insert_id() );
		$this->savetags();
		return $result;
	}

	/**
	 * function update
	 * Updates an existing post in the posts table
	 */	 	 	 	 	
	public function update()
	{
		$this->updated = date('Y-m-d h:i:s');
		if(isset($this->fields['guid'])) unset( $this->newfields['guid'] );
		// invoke plugins for all fields which have been changed
		// For example, a plugin action "update_post_status" would be
		// triggered if the post has a new status value
		foreach ( $this->newfields as $fieldname => $value )
		{
			Plugins::act('update_post_' . $fieldname, $this, $value );
		}
		$result = parent::update( DB::table('posts'), array('slug'=>$this->slug) );
		$this->fields = array_merge($this->fields, $this->newfields);
		$this->newfields = array();
		$this->savetags();
		$this->info->commit();
		return $result;
	}
	
	/**
	 * function delete
	 * Deletes an existing post
	 * @param Boolean whether to delete the post immediately, or set its status to "deleted"
	 */	 	 	 	 	
	public function delete( $delete_now = FALSE )
	{
		if ( ! $delete_now )
		{
			$this->status= Post::status('deleted');
			$this->update();
			return;
		}

		// invoke plugins
		Plugins::act('post_delete', $this);

		// Delete all comments associated with this post
		if(!empty($this->comments))
			$this->comments->delete();
		return parent::delete( DB::table('posts'), array('slug'=>$this->slug) );
	}
	
	/**
	 * function publish
	 * Updates an existing post to published status
	 * @return boolean True on success, false if not	 
	 */
	public function publish()
	{
		$this->status = 'publish';
		return $this->update();
	}
	
	/**
	 * function __get
	 * Overrides QueryRecord __get to implement custom object properties
	 * @param string Name of property to return
	 * @return mixed The requested field value	 
	 **/	 	 
	public function __get( $name )
	{
		$fieldnames = array_keys($this->fields) + array('permalink', 'tags', 'comments', 'comment_count', 'author');
		if( !in_array( $name, $fieldnames ) && strpos( $name, '_' ) !== false ) {
			preg_match('/^(.*)_([^_]+)$/', $name, $matches);
			list( $junk, $name, $filter ) = $matches;
		}
		else {
			$filter = false;
		}

		switch($name) {
		case 'permalink':
			$out = $this->get_permalink();
			break;
		case 'tags':
			$out = $this->get_tags();
			break;
		case 'comments':
			$out = $this->get_comments();
			break;
		case 'comment_count':
			$out = $this->get_comments()->count();
			break;
		case 'author':
			$out = $this->get_author();
			break;
		case 'info':
			$out = $this->get_info();
			break;
		default:
			$out = parent::__get( $name );
			break;
		}
		$out = Plugins::filter( "post_{$name}", $out );
		if( $filter ) {
			$out = Plugins::filter( "post_{$name}_{$filter}", $out );
		}
		return $out;
	}

	/**
	 * function __set
	 * Overrides QueryRecord __get to implement custom object properties
	 * @param string Name of property to return
	 * @return mixed The requested field value	 
	 **/	 	 
	public function __set( $name, $value )
	{
		switch($name) {
		case 'pubdate':
			$value = date( 'Y-m-d H:i:s', strtotime( $value ) );
			break;
		case 'tags':
			$this->tags = $this->parsetags( $value );
			return $this->get_tags();
		case 'status':
			return $this->setstatus($value);
		}
		return parent::__set( $name, $value );
	}
	
	/**
	 * function get_permalink
	 * Returns a permalink for the ->permalink property of this class.
	 * @return string A link to this post.	 
	 **/	 	 	
	private function get_permalink()
	{
		return URL::get( 'display_posts_by_slug', array( 'slug' => $this->fields['slug'] ) );
		// @todo separate permalink rule?
	}
	
	/**
	 * function get_tags
	 * Gets the tags for the post
	 * @return &array A reference to the tags array for this post
	 **/	 	 	 	
	private function get_tags() {
		if ( empty( $this->tags ) ) {
			$sql= "
				SELECT t.tag_text
				FROM " . DB::table('tags') . " t
				INNER JOIN " . DB::table('tag2post') . " t2p 
				ON t.id = t2p.tag_id
				WHERE t2p.post_id = ?";
			$this->tags= DB::get_column( $sql, array( $this->fields['id'] ) );
		}	
		if ( count( $this->tags ) == 0 )
			return '';
		return $this->tags;
	}

	/**
	 * function get_comments
	 * Gets the comments for the post
	 * @return &array A reference to the comments array for this post
	**/
	private function &get_comments()
	{
		if ( ! $this->comments_object ) {
			$this->comments_object= Comments::by_post_id( $this->id );
		}
		return $this->comments_object;
	}

	/**
	 * function get_info
	 * Gets the info object for this post, which contains data from the postinfo table
	 * related to this post.
	 * @return PostInfo object
	**/
	private function get_info()
	{
		if ( ! $this->info ) {
			$this->info= new PostInfo( $this->id );
		}
		return $this->info;
	}
 
	/**
	 * private function get_author()
	 * returns a User object for the author of this post
	 * @return User a User object for the author of the current post
	**/
	private function get_author()
	{
		if ( ! isset( $this->author_object ) ) {
			// XXX for some reason, user_id is a string sometimes?
			$this->author_object= User::get_by_id( $this->user_id );
		}
		return $this->author_object;
	}
}
?>
