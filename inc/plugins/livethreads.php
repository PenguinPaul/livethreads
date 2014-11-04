<?php
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// With your other hooks
$plugins->add_hook('xmlhttp', 'livethreads_xmlhttp');
$plugins->add_hook('showthread_start','livethreads_js');

function livethreads_info()
{
	return array(
		"name"			=> "Live Threads",
		"description"	=> "Adds a simple blog to your forum.",
		"website"		=> "https://github.com/PenguinPaul/livethreads",
		"author"		=> "Paul Hedman",
		"authorsite"	=> "http://www.paulhedman.com",
		"version"		=> "0.6",
		"compatibility" => "18*",
		"codename"		=> "livethreads"
	);
}

function livethreads_install()
{
	global $db;
	

	// Install settings 
	// Delete old settings
	$db->query("DELETE FROM ".TABLE_PREFIX."settings WHERE name LIKE 'lt_%'");
	$db->query("DELETE FROM ".TABLE_PREFIX."settinggroups WHERE name='livethreads'");

	$group = array(
		'gid'			=> 'NULL',
		'name'			=> 'livethreads',
		'title'			=> 'Live Threads Settings',
		'description'	=> 'Settings for the Live Threads plugin.',
		'disporder'		=> '',
		'isdefault'		=> 'no',
	);
	$db->insert_query('settinggroups', $group);
	$gid = $db->insert_id();
	
	$setting = array(
		'name'			=> 'lt_refreshrate',
		'title'			=> 'Refresh rate',
		'description'	=> 'The refresh rate, in milliseconds, e.g. 1000 = one second, 20000 = 20 seconds.',
		'optionscode'	=> 'text',
		'value'			=> '5000',
		'disporder'		=> 1,
		'gid'			=> intval($gid),
	);
	$db->insert_query('settings', $setting);

	$setting = array(
		'name'			=> 'lt_creategroups',
		'title'			=> 'Creator Groups',
		'description'	=> 'Groups that can make a thread a live thread.',
		'optionscode'	=> 'groupselect',
		'value'			=> '3,4',
		'disporder'		=> 2,
		'gid'			=> intval($gid),
	);
	$db->insert_query('settings', $setting);

	$setting = array(
		'name'			=> 'lt_viewergroups',
		'title'			=> 'Viewer Groups',
		'description'	=> 'Groups that can view a live thread (as a live thread; they can still view them normally if denied)',
		'optionscode'	=> 'groupselect',
		'value'			=> '2,3,4,6',
		'disporder'		=> 3,
		'gid'			=> intval($gid),
	);
	$db->insert_query('settings', $setting);

	rebuild_settings();

	// Add thread edit

	$db->add_column('threads', 'livethread', 'BOOLEAN NOT NULL DEFAULT FALSE');
}

function livethreads_is_installed()
{
	global $db;
	return $db->field_exists('livethread', 'threads');
}

function livethreads_activate()
{
	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
	//get rid of old templates
	livethreads_deactivate();
	
	find_replace_templatesets('showthread',
		'#' . preg_quote('{$headerinclude}') . '#',
		'{$headerinclude}{$ltjs}'
	);
}

function livethreads_deactivate()
{
	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets('showthread',
		'#' . preg_quote('{$ltjs}') . '#',
		''
	);
}

function livethreads_uninstall()
{
	global $db;
	// Byebye settings
	$db->query("DELETE FROM ".TABLE_PREFIX."settings WHERE name LIKE 'lt_%'");
	$db->query("DELETE FROM ".TABLE_PREFIX."settinggroups WHERE name='livethreads'");
	rebuild_settings();
	// Byebye column
	$db->drop_column("threads","livethread");
}


// In the body of your plugin
function livethreads_xmlhttp()
{
	global $mybb, $charset, $db, $altbg, $postcounter, $attachcache;

	if($mybb->get_input('action') == 'livethread')
	{
		header("Content-type: application/json; charset={$charset}");
		// Get the thread & timestamp & post bg
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		$thread = get_thread($tid);
		$timestamp = $mybb->get_input('timestamp', MyBB::INPUT_INT);
		if($timestamp == 0)
		{
			// No time set, so default to right now.
			$timestamp = TIME_NOW;
		}

		// Does the thread exist?
		if($thread)
		{
			// Can the user view this thread?
			$forumpermissions = forum_permissions($thread['fid']);
			if($forumpermissions['canview'] == 1 && $forumpermissions['canviewthreads'] == 1 && in_array($mybb->user['usergroup'], explode(',', $mybb->settings['lt_viewergroups'])) && $thread['livethread'])
			{
				if(is_moderator($fid))
				{
					$ismod = true;
				} else {
					$ismod = false;
				}

				if($ismod == true)
				{
					$postcounter = $thread['replies'] + $thread['unapprovedposts'];
				} else {
					$postcounter = $thread['replies'];
				}

				if(($postcounter - $mybb->settings['postsperpage']) % 2 != 0)
				{
					$altbg = "trow1";
				} else {
					$altbg = "trow2";
				}

				$query = $db->query("
					SELECT u.*, u.username AS userusername, p.*, f.*, eu.username AS editusername
					FROM ".TABLE_PREFIX."posts p
					LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.uid)
					LEFT JOIN ".TABLE_PREFIX."userfields f ON (f.ufid=u.uid)
					LEFT JOIN ".TABLE_PREFIX."users eu ON (eu.uid=p.edituid)
					WHERE p.dateline>=".($timestamp-1)."
					ORDER BY p.dateline
				");

				// Get ready to send the post
				require_once("inc/functions_post.php");
				require_once MYBB_ROOT."inc/class_parser.php";
				$parser = new postParser;
				$posts = array();

				while($post = $db->fetch_array($query))
				{
					// Attachments
					$attachments = $db->simple_select("attachments", "*", "pid='{$post['pid']}'");
					while($attachment = $db->fetch_array($attachments))
					{
						$attachcache[$attachment['pid']][$attachment['aid']] = $attachment;
					}
					// Loop through, and encode so no json errors
					$posts[] = base64_encode(build_postbit($post));
				}

				$postsjson = json_encode($posts);
				$data = array('status' => 200, 'posts' => $postsjson);
				echo json_encode($data);
				exit;
			} else {
				// You don't have permission to view that that thread!
				$data = array('status' => 403, 'livethread' => $thread['livethread']);
				echo json_encode($data);
				exit;
			}
		} else {
			// 404, thread not found!
			$data = array('status' => 404);
			echo json_encode($data);
			exit;
		}
	}
}

function livethreads_js()
{
	global $ltjs, $tid, $thread, $mybb;
	$thread = get_thread($tid);
	if(in_array($mybb->user['usergroup'], explode(',', $mybb->settings['lt_viewergroups'])) && $thread['livethread'])
	{
		$ltjs = '
	<script type="text/javascript">
		// Live Threads
		$( document ).ready(function() {
			var timestamp = '.TIME_NOW.';
			var lastpid = $(\'#lastpid\');
			var lastpid = lastpid.val();
			// We only want to livethread on the last page
			if($(\'#post_\'+lastpid).length != 0)
			{
				var refreshId = setInterval(function()
				{
					$.get(\'xmlhttp.php?action=livethread&tid='.$tid.'&timestamp=\'+timestamp,
						function(result) {
						status = JSON.stringify(result.status)
						if(status == \'200\')
						{
							var posts = $.parseJSON(JSON.stringify(result.posts))
							var myposts = $.parseJSON(posts)
							$.each( myposts, function( index, post ){
								post = atob(post);
								if(post.match(/id="post_([0-9]+)"/))
								{
									var pid = post.match(/id="post_([0-9]+)"/)[1];
								}

								if($(\'#post_\'+pid).length == 0)
								{
									$(\'#posts\').append(\'<span style="" class="liveposts[]">\'+post+\'</span>\');
									$(".liveposts").last().fadeIn(\'slow\');
								}
							});
						} else {
							// Not 200, not ok, see what\'s up
							if(status == \'403\')
							{
								// Forbidden?
								if(JSON.stringify(result.livethread) == 0)
								{
									// Not a live thread!
									$.jGrowl(\'You do not have permission to view this as a Live Thread\');
								} else {
									// You can\'t view the thread in general.
									$.jGrowl(\'You do not have permission to view this thread\');
								}
							} else if (status == \'404\') {
								// Thread non existent!
								$.jGrowl(\'Thread not found!\');
							}
						}
					});
					timestamp = Math.round(+new Date()/1000);
				}, '.intval($mybb->settings['lt_refreshrate']).');
			}
		});
	</script>';
	}
}

?>
