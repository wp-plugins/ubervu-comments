<?php
/*
Plugin Name: uberVU Comments
Plugin URI: http://www.ubervu.com
Description: This plugin displays reactions to your posts from all over the web using the <a href='http://www.contextvoice.com'>ContextVoice </a> API
Author: uberVU Team
Version: 0.1
Author URI: http://www.ubervu.com
*/

function throwException($message = null,$code = null) {
    throw new Exception($message,$code);
}

function file_get_contents_curl($szURL) {
  $pCurl = curl_init($szURL);

  curl_setopt($pCurl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($pCurl, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($pCurl, CURLOPT_TIMEOUT, 10);

  $szContents = curl_exec($pCurl);
  $aInfo = curl_getinfo($pCurl);

  if($aInfo['http_code'] === 200)
    return $szContents;

  throwException('Curl failed', -1);
}

function addUbervuReactions($commentPostId, $authorName, $authorURL, $authorImage, $datecreated, $content, $parent) {
	global $wpdb;
	
	$commentModel = array('comment_ID' => 'NULL', 'comment_post_ID' => $commentPostId, 'comment_author' => $authorName, 'comment_author_email' => '', 'comment_author_url' => $authorURL, 'comment_author_IP' => $authorImage, 'comment_date' => date('Y-m-d H:i:s', floatval($datecreated)), 'comment_date_gmt' => date('Y-m-d H:i:s', floatval($datecreated)), 'comment_content' => $content, 'comment_karma' => 0, 'comment_approved' => 1, 'comment_agent' => 'uberVU', 'comment_type' => 'comment', 'comment_parent' => $parent, 'user_id' => 0);
	$wpdb->query("INSERT INTO `$wpdb->comments` (`".implode("`, `", array_keys($commentModel))."`) VALUES ('".implode("', '", array_values($commentModel))."');");
}

function updatePostCommentCount($postId, $commentCount) {
	global $wpdb;
	
	$wpdb->query("UPDATE `$wpdb->posts` SET `comment_count` = '$commentCount' WHERE ID = '$postId'");
}

function loopReactions($post, $rsp_obj, $since_url, $parent = 0) {
  global $commentCount;
  if ((int)$rsp_obj->attributes()->count > 0) {
		foreach ($rsp_obj->reaction as $r) {
      
      $author = $r->author->attributes();
      $author_url = ''; $author_image = '';
      if ($author->url) $author_url = (string)$author->url;
      if ($author->image) $author_image = (string)$author->image;
      
			if (strpos(get_permalink(), $r->attributes()->url) === FALSE) {
			  if ((int)$r->attributes()->created_at >= $since_url)
			    $since_url = (int)$r->attributes()->created_at+1;
			    
			  $postURL = get_permalink($post->ID);
			  if (substr($postURL, 0, 7) == 'http://') $postURL = substr($postURL, 7);
			  $content = (string)$r->content.'<p>via <a href="http://www.ubervu.com/conversations/'.urlencode($postURL).'">uberVU</a></p>';
			  
				addUbervuReactions($post->ID, (string)$author->name, $author_url, $author_image, (string)$r->attributes()->published, $content, $parent);
				
				if ($r->children)
				  $since_url = loopReactions($post, $r->children, $since_url, mysql_insert_id());
				$commentCount++;
			}
		}
	}
	return $since_url;
}

function getUbervuReactions($postArray) {
  global $services, $commentCount, $updateInterval;
  
  if (count($postArray) > 1) return $postArray;
  $post = $postArray[0];
  
  $postUrl = get_permalink($post->ID);
  
  $lastUpdated = get_option('ubervu_last_updated');
  if ($lastUpdated) {
    $lastUpdated = unserialize($lastUpdated);
    $postUpdated = 0;
    if (isset($lastUpdated[$postUrl]))
      $postUpdated = $lastUpdated[$postUrl];
      
    if (time() < (int)$postUpdated+$updateInterval)
      return $postArray;
  }
  else
    add_option('ubervu_last_updated', serialize(array()), null, 'no');
  
  $since = get_option('ubervu_since');
  if ($since)
    $since = unserialize($since);
  else
    add_option('ubervu_since', serialize(array()), null, 'no');
  
	$params = array('apikey'=>'udheetyregnrz2gbmdjhv8zv', 'perpage' => '100', 'threaded' => 'true', 'url' => urlencode($postUrl));
	
	if (get_option('ubervu_threaded') == 'no') unset($params['threaded']);
	
	$exclude = array();
	foreach ($services as $service) {
	  if (get_option('ubervu_include_'.$service) == 'no') $exclude[] = $service;
	}
	if (!empty($exclude))
	  $params['exclude[generator]'] = implode(',', $exclude);
	
	if (get_option('ubervu_show_retweets') == 'no') $params['filter'] = 'remove-retwitts';
	
	$since_url = ($since)?$since[$postUrl]:0;
	if ($since_url != 0) $params['since'] = $since_url;
	
	$get = array();
	foreach ($params as $k => $v) $get[] = $k.'='.$v;
	$get = implode('&', $get);
	$url = "http://api.contextvoice.com/1.2/reactions/?".$get;
	try {
		$rsp = file_get_contents_curl($url);
		$rsp_obj = new SimpleXMLElement($rsp);
	}
	catch (Exception $e) {
    return $postArray;
	}
	
	//Loop reactions
	$commentCount = 0;
	$since_url = loopReactions($post, $rsp_obj, $since_url);
	
	$commentCount += $post->comment_count;
  $post->comment_count = $commentCount;
  updatePostCommentCount($post->ID, $commentCount);
  $_SESSION['uv_comment_count'] = $commentCount;
  
  $since[$postUrl] = $since_url;
	update_option('ubervu_since', serialize($since));
	
	$lastUpdated[$postUrl] = time();
	update_option('ubervu_last_updated', serialize($lastUpdated));
	
	return $postArray;
}

function ubervuPluginMenu() {
    add_options_page('uberVU Comments', 'uberVU Comments', 'administrator', 'ubervuoptions', 'options_page');
}

function options_page() {
  global $services;
?>
<div class="wrap">
<h2>uberVU Comments</h2>

<form method="post" action="options.php">
<?php wp_nonce_field('update-options'); ?>

<table class="form-table">

<tr valign="top">
<th scope="row">Threaded conversation</th>
<td>
  <input type="radio" name="ubervu_threaded" value="yes" <?=(get_option("ubervu_threaded")=='yes' || !get_option("ubervu_threaded"))?'checked="checked"':''?>>Yes</option>
  <input type="radio" name="ubervu_threaded" value="no" <?=(get_option("ubervu_threaded")=='no')?'checked="checked"':''?>>No</option>
</td>
</tr>

<tr valign="top">
<th scope="row">Include platforms</th>
<td>
<table border="0" cell-padding="0" cell-spacing="0">
<?
$options = array();
foreach ($services as $service) { ?>
<tr>
<td>
<?=ucfirst($service)?>
</td>
<td>
<input type="radio" name="ubervu_include_<?=$service?>" value="yes" <?=(get_option("ubervu_include_$service")=='yes' || !get_option("ubervu_include_$service"))?'checked="checked"':''?>>Yes</option>
<input type="radio" name="ubervu_include_<?=$service?>" value="no" <?=(get_option("ubervu_include_$service")=='no')?'checked="checked"':''?>>No</option>
</td>
</tr>
<? $options[] = 'ubervu_include_'.$service; } ?>
</table>
</td>
</tr>

<tr valign="top">
<th scope="row">Import retweets</th>
<td>
  <input type="radio" name="ubervu_show_retweets" value="yes" <?=(get_option("ubervu_show_retweets")=='yes' || !get_option("ubervu_show_retweets"))?'checked="checked"':''?>>Yes</option>
  <input type="radio" name="ubervu_show_retweets" value="no" <?=(get_option("ubervu_show_retweets")=='no')?'checked="checked"':''?>>No</option>
</td>
</tr>

</table>

<input type="hidden" name="action" value="update" />
<input type="hidden" name="page_options" value="ubervu_threaded,<?=implode(',', $options)?>,ubervu_show_retweets" />

<p class="submit">
<input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
</p>

</form>
</div>
<?
}

function realCommentsNumber($nr) {
  if (isset($_SESSION['uv_comment_count'])) {
    $number = $_SESSION['uv_comment_count'];
    unset($_SESSION['uv_comment_count']);
    return $number;
  }
  return $nr;
}

function replaceAvatars($img, $comment) {
  $avatar = $comment->comment_author_IP;
  if ($avatar && substr($avatar, 0, 7) == 'http://')
    return preg_replace("/src='[^']+'/", "src='".$comment->comment_author_IP."'", $img);
  return $img;
}

$commentCount = 0;
$services = array('twitter', 'friendfeed', 'digg', 'wordpress', 'blogger', 'typepad', 'disqus', 'flickr', 'picasa', 'youtube', 'vimeo', 'delicious', 'reddit', 'hackernews', 'mixx', 'stumbleupon', 'nytimes', 'slashdot', 'yahoobuzz');

if(!isset($_SESSION))
  session_start();

$updateInterval = 3600; // seconds

add_filter('the_posts', 'getUbervuReactions', 1);
add_filter('get_avatar', 'replaceAvatars', 99, 2);
add_filter('get_comments_number', 'realCommentsNumber', 1);
add_action('admin_menu', 'ubervuPluginMenu');

?>