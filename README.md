livethreads
===========

Live Threads for MyBB.  New posts on the last page will show up automatically.

###Todo

-Check for security. (~~Forum password~~, etc.)

-Fix inline moderation


### Changelog

#### Version 0.7

-Added checking for the forum password

-Added the ability for mods/admins to set a thread as a live thread.

-Added the setting for forums that have all threads as live threads

-Added the ability for users to enable/disable live thread functionality locally.

#### Version 0.6
-Added attachment support

-Threads now only live on the last page of discussion.

-Added error popups via jGrowl

-Removed unneeded template edits

-You still must manually make a thread a live thread in the database be changeing the "livethread" field in mybb_threads to 1.

#### Version 0.5
-Initial commit.

-You must manually make a thread a live thread in the database be changeing the "livethread" field in mybb_threads to 1.
