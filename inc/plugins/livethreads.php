<?php

/*
	Live Threads

	Copyright (c) 2014 Paul Hedman

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in all
	copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
	SOFTWARE.

	(MIT License)
*/

if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// With your other hooks
$plugins->add_hook('xmlhttp', 'livethreads_xmlhttp');
$plugins->add_hook('showthread_start', 'livethreads_showthread_start');
$plugins->add_hook('misc_start', 'livethreads_misc_start');

function livethreads_info()
{
	return array(
		"name"			=> "Live Threads",
		"description"	=> "New posts on the last page of the showthread will show up automatically via AJAX.",
		"website"		=> "https://github.com/PenguinPaul/livethreads",
		"author"		=> "Paul Hedman",
		"authorsite"	=> "http://www.paulhedman.com",
		"version"		=> "0.8.2",
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

	$setting = array(
		'name'			=> 'lt_defaultonforums',
		'title'			=> 'Always Active Forums',
		'description'	=> 'Forums where all threads are live threads (Can be resource intensive!)',
		'optionscode'	=> 'forumselect',
		'value'			=> '',
		'disporder'		=> 4,
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

	find_replace_templatesets('showthread',
		'#' . preg_quote('{$threadnotesbox}') . '#',
		'{$threadnotesbox}{$ltbutton}'
	);
}

function livethreads_deactivate()
{
	require_once MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets('showthread',
		'#' . preg_quote('{$ltjs}') . '#',
		''
	);

	find_replace_templatesets('showthread',
		'#' . preg_quote('{$ltbutton}') . '#',
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
			check_forum_password($thread['fid']);
			if($forumpermissions['canview'] == 1 && $forumpermissions['canviewthreads'] == 1 && is_member($mybb->settings['lt_viewergroups']) && ($thread['livethread'] || (in_array($thread['fid'], explode(',', $mybb->settings['lt_defaultonforums'])) || $mybb->settings['lt_defaultonforums'] == '-1')))
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
					WHERE p.dateline>={$timestamp} AND p.tid='{$thread['tid']}'
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

function livethreads_showthread_start()
{
	global $ltjs, $ltbutton, $tid, $thread, $mybb, $lang;
	$thread = get_thread($tid);

	$lang->load('livethreads');

	$ltbutton = '';
	$postkey = generate_post_check();

	// Options for those who can change live thread status
	if(is_member($mybb->settings['lt_creategroups']))
	{
		// Is the thread automatically live due to forum settings?
		if(!in_array($thread['fid'], explode(',', $mybb->settings['lt_defaultonforums'])) && $mybb->settings['lt_defaultonforums'] != '-1')
		{
			// Nope, so we can edit settings.
			if($thread['livethread'])
			{
				$ltbutton .= "<div class=\"postbit_buttons postbit_edit\"><a href=\"misc.php?action=livethread_deactivate&tid={$tid}&my_post_key={$postkey}\" class=\"postbit_qdelete\"><span>{$lang->lt_deactivate}</span></a></div><br />";
			} else {
				$ltbutton .= "<div class=\"postbit_buttons postbit_edit\"><a href=\"misc.php?action=livethread_activate&tid={$tid}&my_post_key={$postkey}\" class=\"postbit_qrestore\"><span>{$lang->lt_activate}</span></a></div><br />";		
			}
		} else {
			// Yes, the moderator can't do anything.
			$ltbutton .= "<div class=\"postbit_buttons\"><a href=\"javascript:$.jGrowl('This thread is live via forum settings and cannot be disabled.');\" class=\"postbit_warn\"><span>{$lang->lt_forumset}</span></a></div><br />";	
		}
	}

	// Javascript
	if(is_member($mybb->settings['lt_viewergroups']) && ($thread['livethread'] || (in_array($thread['fid'], explode(',', $mybb->settings['lt_defaultonforums'])) || $mybb->settings['lt_defaultonforums'] == '-1')))
	{
		// Options for those who can view live threads
		if(is_member($mybb->settings['lt_viewergroups']))
		{
			// Is the live thread enabled?
			if(isset($mybb->cookies['lt_ignored']) && in_array($tid, explode(',', $mybb->cookies['lt_ignored'])))
			{
				// It's not enabled, show the enable button
				$ltbutton .= "<div class=\"postbit_buttons\"><a href=\"misc.php?action=livethread_enable&tid={$tid}&my_post_key={$postkey}\" class=\"postbit_qrestore\"><span>{$lang->lt_enable}</span></a></div><br />";	
			} else {
				// Enabled, show the disable button
				$ltbutton .= "<div class=\"postbit_buttons\"><a href=\"misc.php?action=livethread_disable&tid={$tid}&my_post_key={$postkey}\" class=\"postbit_qdelete\"><span>{$lang->lt_disable}</span></a></div><br />";	
			}
		}

		// If they don't want to ignore the live thread...
		if(!isset($mybb->cookies['lt_ignored']) || !in_array($tid, explode(',', $mybb->cookies['lt_ignored'])))
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
										// Make sure the quickreply doesn\'t break things
										var lastpid = $(\'#lastpid\');
										if(lastpid)
										{
											lastpid.val(pid);
										}
									}

									if($(\'#post_\'+pid).length == 0)
									{
										$(\'#posts\').append(post);
									}
								});
							} else {
								// Not 200, not ok, see what\'s up
								if(status == \'403\')
								{
									$.jGrowl(\''.$lang->lt_nopermlive.'\');
								} else if (status == \'404\') {
									// Thread non existent!
									$.jGrowl(\''.$lang->lt_notfound.'\');
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
}

function livethreads_misc_start()
{
	global $mybb, $db;
	if($mybb->get_input('action') == 'livethread_deactivate')
	{
		// Moderator/etc is deactivating a live thread
		// Check for CSRF
		verify_post_check($mybb->get_input('my_post_key'));
		// Get TID
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		// Check to make sure the user can update thread statuses
		if(is_member($mybb->settings['lt_creategroups']))
		{
			// Disable the thread
			$update['livethread'] = '0';
			$db->update_query('threads',$update,"tid='{$tid}'");
			// And send them back
			redirect(get_thread_link($tid, 0, "lastpost"));
		} else {
			// https://www.youtube.com/watch?v=usQ8AhiRcNE
			error_no_permission();
		}
	} elseif($mybb->get_input('action') == 'livethread_activate')
	{
		// We're making a thread live!
		// CSRF protection
		verify_post_check($mybb->get_input('my_post_key'));
		// Grab the TID by the horns
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		// Can this guy even do this?  What are his qualifications?
		if(is_member($mybb->settings['lt_creategroups']))
		{
			// Apply defibrillator and make the thread live!
			$update['livethread'] = '1';
			$db->update_query('threads',$update,"tid='{$tid}'");
			redirect(get_thread_link($tid, 0, "lastpost"));
		} else {
			// Oops.  You aren't a doctor.  You can't make things live.
			error_no_permission();
		}
	} elseif($mybb->get_input('action') == 'livethread_disable') {
		// Check for Cross Site Request Forgers
		verify_post_check($mybb->get_input('my_post_key'));
		// Either they didn't forge or are good at it.  Moving on...
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		// Do they even ignore anything?
		if(isset($mybb->cookies['lt_ignored']))
		{
			// Ignore the thread and break its heart
			$new_ignored = explode(',', $mybb->cookies['lt_ignored']);
			$new_ignored[] = $tid;
			$mybb->cookies['lt_ignored'] = implode(',', $new_ignored);
			my_setcookie("lt_ignored", $mybb->cookies['lt_ignored']);
		} else {
			// See previous comment
			my_setcookie("lt_ignored", $tid);
		}
		// Return them to the depths from whence they came
		redirect(get_thread_link($tid, 0, "lastpost"));
	} elseif($mybb->get_input('action') == 'livethread_enable') {
		// Again checking for forgeries
		verify_post_check($mybb->get_input('my_post_key'));
		$tid = $mybb->get_input('tid', MyBB::INPUT_INT);
		// Are they ignoring anthing?
		if(isset($mybb->cookies['lt_ignored']))
		{
			// Bring the live thread to light!
			$new_ignored = explode(',', $mybb->cookies['lt_ignored']);
			// Look for the tid in the ignored threads
			$key = array_search($tid, $new_ignored);
			if($key !== false)
			{
				// If it's in there, get rid of it
				unset($new_ignored[$key]);
			}
			// Update ignored threads
			$mybb->cookies['lt_ignored'] = implode(',', $new_ignored);
			my_setcookie("lt_ignored", $mybb->cookies['lt_ignored']);
			// Let's blow this thing and go home!
			redirect(get_thread_link($tid, 0, "lastpost"));
		}
	}
}

?>
