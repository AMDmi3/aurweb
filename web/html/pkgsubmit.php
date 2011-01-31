<?php

set_include_path(get_include_path() . PATH_SEPARATOR . '../lib');
include_once("config.inc");

require_once('Archive/Tar.php');
require_once('Find.php');

include_once("aur.inc");         # access AUR common functions
include_once("pkgfuncs.inc");    # package functions

set_lang();                 # this sets up the visitor's language
check_sid();                # see if they're still logged in

$cwd = getcwd();

if ($_COOKIE["AURSID"]):

	# Track upload errors
	$error = "";

	if (isset($_REQUEST['pkgsubmit'])) {

		# Before processing, make sure we even have a file
		if ($_FILES['pfile']['size'] == 0){
			$error = __("Error - No file uploaded");
		}

		$uid = uid_from_sid($_COOKIE['AURSID']);

		if (!$error) {
			$tar = new Archive_Tar($_FILES['pfile']['tmp_name']);

			# Extract PKGBUILD into a string
			$pkgbuild_raw = '';
			foreach ($tar->listContent() as $tar_file) {
				if (preg_match('/^[^\/]+\/PKGBUILD$/', $tar_file['filename'])) {
					$pkgbuild_raw = $tar->extractInString($tar_file['filename']);
					break;
				}
			}

			if (empty($pkgbuild_raw)) {
				$error = __("Error trying to unpack upload - PKGBUILD does not exist.");
			}
		}

		# if no error, get list of directory contents and process PKGBUILD
		# TODO: This needs to be completely rewritten to support stuff like arrays
		# and variable substitution among other things.
		if (!$error) {
			# process PKGBUILD - remove line concatenation
			#
			$pkgbuild = array();
			$line_no = 0;
			$lines = array();
			$continuation_line = 0;
			$current_line = "";
			$paren_depth = 0;
			foreach (split("\n", $pkgbuild_raw) as $line) {
				$line = trim($line);
				# Remove comments
				$line = preg_replace('/\s*#.*/', '', $line);

				$char_counts = count_chars($line, 0);
				$paren_depth += $char_counts[ord('(')] - $char_counts[ord(')')];
				if (substr($line, strlen($line)-1) == "\\") {
					# continue appending onto existing line_no
					#
					$current_line .= substr($line, 0, strlen($line)-1);
					$continuation_line = 1;
				} elseif ($paren_depth > 0) {
					# assumed continuation
					# continue appending onto existing line_no
					#
					$current_line .= $line . " ";
					$continuation_line = 1;
				} else {
					# maybe the last line in a continuation, or a standalone line?
					#
					if ($continuation_line) {
						# append onto existing line_no
						#
						$current_line .= $line;
						$lines[$line_no] = $current_line;
						$current_line = "";
					} else {
						# it's own line_no
						#
						$lines[$line_no] = $line;
					}
					$continuation_line = 0;
					$line_no++;
				}
			}

			# Now process the lines and put any var=val lines into the
			# 'pkgbuild' array.
			while (list($k, $line) = each($lines)) {
				# Neutralize parameter substitution
				$line = preg_replace('/\${(\w+)#(\w*)}?/', '$1$2', $line);

				$lparts = Array();
				# Match variable assignment only.
				if (preg_match('/^\s*[_\w]+=[^=].*/', $line, $matches)) {
					$lparts = explode("=", $matches[0], 2);
				}

				if (!empty($lparts)) {
					# this is a variable/value pair, strip out
					# array parens and any quoting, except in pkgdesc
					# for pkgdesc, only remove start/end pairs of " or '
					if ($lparts[0]=="pkgdesc") {
						if ($lparts[1]{0} == '"' &&
								$lparts[1]{strlen($lparts[1])-1} == '"') {
							$pkgbuild[$lparts[0]] = substr($lparts[1], 1, -1);
						}
						elseif
							($lparts[1]{0} == "'" &&
							 $lparts[1]{strlen($lparts[1])-1} == "'") {
							$pkgbuild[$lparts[0]] = substr($lparts[1], 1, -1);
						} else {
							$pkgbuild[$lparts[0]] = $lparts[1];
						}
					} else {
						$pkgbuild[$lparts[0]] = str_replace(array("(",")","\"","'"), "",
								$lparts[1]);
					}
				}
			}

			# some error checking on PKGBUILD contents - just make sure each
			# variable has a value. This does not do any validity checking
			# on the values, or attempts to fix line continuation/wrapping.
			$req_vars = array("url", "pkgdesc", "license", "pkgrel", "pkgver", "arch", "pkgname");
			foreach ($req_vars as $var) {
				if (!array_key_exists($var, $pkgbuild)) {
					$error = __('Missing %s variable in PKGBUILD.', $var);
					break;
				}
			}
		}

		# TODO This is where other additional error checking can be
		# performed. Examples: #md5sums == #sources?, md5sums of any
		# included files match?, install scriptlet file exists?
		#

		# Check for http:// or other protocol in url
		#
		if (!$error) {
			$parsed_url = parse_url($pkgbuild['url']);
			if (!$parsed_url['scheme']) {
				$error = __("Package URL is missing a protocol (ie. http:// ,ftp://)");
			}
		}

		# Now, run through the pkgbuild array, and do "eval" and simple substituions.
		if (!$error) {
			while (list($k, $v) = each($pkgbuild)) {
				if (strpos($k,'eval ') !== false) {
					$k = preg_replace('/^eval[\s]*/', "", $k);
					##"eval" replacements
					$pattern_eval = '/{\$({?)([\w]+)(}?)}/';
					while (preg_match($pattern_eval,$v,$regs)) {
						$pieces = explode(",",$pkgbuild["$regs[2]"]);
						## nongreedy matching! - preserving the order of "eval"
						$pattern = '/([\S]*?){\$'.$regs[1].$regs[2].$regs[3].'}([\S]*)/';
						while (preg_match($pattern,$v,$regs_replace)) {
							$replacement = "";
							for ($i = 0; $i < sizeof($pieces); $i++) {
								$replacement .= $regs_replace[1].$pieces[$i].$regs_replace[2]." ";
							}
							$v=preg_replace($pattern, $replacement, $v, 1);
						}
					}
				}

				# Simple variable replacement
				$pattern_var = '/\$({?)([_\w]+)(}?)/';
				$offset = 0;
				while (preg_match($pattern_var, $v, $regs, PREG_OFFSET_CAPTURE, $offset)) {
					$var = $regs[2][0];
					$pos = $regs[0][1];
					$len = strlen($regs[0][0]);

					if (isset($new_pkgbuild[$var])) {
						$replacement = explode(" ", $new_pkgbuild[$var], 2);
					}
					elseif (isset($pkgbuild[$var]) && $var != $k) {
						$replacement = explode(" ", $pkgbuild[$var], 2);
					}
					else {
						$replacement = '';
					}

					$v = substr_replace($v, $replacement, $pos, $len);
					$offset += strlen($replacement);
				}
				$new_pkgbuild[$k] = $v;
			}
		}

		# Now we've parsed the pkgbuild, let's move it to where it belongs
		if (!$error) {
			$pkg_name = str_replace("'", "", $new_pkgbuild['pkgname']);
			$pkg_name = escapeshellarg($pkg_name);
			$pkg_name = str_replace("'", "", $pkg_name);

			$presult = preg_match("/^[a-z0-9][a-z0-9\.+_-]*$/", $pkg_name);

			if (!$presult) {
				$error = __("Invalid name: only lowercase letters are allowed.");
			}
		}

		if (isset($pkg_name)) {
			$incoming_pkgdir = INCOMING_DIR . $pkg_name;
		}

		if (!$error) {
			# First, see if this package already exists, and if it can be overwritten
			$pkg_exists = package_exists($pkg_name);
			if (can_submit_pkg($pkg_name, $_COOKIE["AURSID"])) {
				if (file_exists($incoming_pkgdir)) {
					# Blow away the existing file/dir and contents
					rm_tree($incoming_pkgdir);
				}

				if (!@mkdir($incoming_pkgdir)) {
					$error = __( "Could not create directory %s.", $incoming_pkgdir);
				}
			} else {
				$error = __( "You are not allowed to overwrite the %h%s%h package.", "<b>", $pkg_name, "</b>");
			}
		}

		if (!$error) {
			if (!@chdir($incoming_pkgdir)) {
				$error = __("Could not change directory to %s.", $incoming_pkgdir);
			}

			file_put_contents('PKGBUILD', $pkgbuild_raw);
			rename($_FILES['pfile']['tmp_name'], $pkg_name . '.tar.gz');
		}

		# Update the backend database
		if (!$error) {

			$dbh = db_connect();

			# This is an overwrite of an existing package, the database ID
			# needs to be preserved so that any votes are retained. However,
			# PackageDepends and PackageSources can be purged.

			$q = "SELECT * FROM Packages WHERE Name = '" . mysql_real_escape_string($new_pkgbuild['pkgname']) . "'";
			$result = db_query($q, $dbh);
			$pdata = mysql_fetch_assoc($result);

			if ($pdata) {

				# Flush out old data that will be replaced with new data
				$q = "DELETE FROM PackageDepends WHERE PackageID = " . $pdata["ID"];
				db_query($q, $dbh);
				$q = "DELETE FROM PackageSources WHERE PackageID = " . $pdata["ID"];
				db_query($q, $dbh);

				# If the package was a dummy, undummy it
				if ($pdata['DummyPkg']) {
					$q = sprintf( "UPDATE Packages SET DummyPkg = 0, SubmitterUID = %d, MaintainerUID = %d, SubmittedTS = UNIX_TIMESTAMP() WHERE ID = %d",
						$uid,
						$uid,
						$pdata["ID"]);

					db_query($q, $dbh);
				}

				# If a new category was chosen, change it to that
				if ($_POST['category'] > 1) {
					$q = sprintf( "UPDATE Packages SET CategoryID = %d WHERE ID = %d",
						mysql_real_escape_string($_REQUEST['category']),
						$pdata["ID"]);

					db_query($q, $dbh);
				}

				# Update package data
				$q = sprintf("UPDATE Packages SET ModifiedTS = UNIX_TIMESTAMP(), Name = '%s', Version = '%s-%s', License = '%s', Description = '%s', URL = '%s', FSPath = '%s', OutOfDateTS = NULL WHERE ID = %d",
					mysql_real_escape_string($new_pkgbuild['pkgname']),
					mysql_real_escape_string($new_pkgbuild['pkgver']),
					mysql_real_escape_string($new_pkgbuild['pkgrel']),
					mysql_real_escape_string($new_pkgbuild['license']),
					mysql_real_escape_string($new_pkgbuild['pkgdesc']),
					mysql_real_escape_string($new_pkgbuild['url']),
					mysql_real_escape_string($incoming_pkgdir . "/" . $pkg_name . ".tar.gz"),
					$pdata["ID"]);

				db_query($q, $dbh);

				# Update package depends
				$depends = explode(" ", $new_pkgbuild['depends']);
				foreach ($depends as $dep) {
					$q = "INSERT INTO PackageDepends (PackageID, DepPkgID, DepCondition) VALUES (";
					$deppkgname = preg_replace("/[<>]?=.*/", "", $dep);
					$depcondition = str_replace($deppkgname, "", $dep);

					if ($deppkgname == "#") {
						break;
					}

					$deppkgid = create_dummy($deppkgname, $_COOKIE['AURSID']);
					$q .= $pdata["ID"] . ", " . $deppkgid . ", '" . mysql_real_escape_string($depcondition) . "')";

					db_query($q, $dbh);
				}

				# Insert sources
				$sources = explode(" ", $new_pkgbuild['source']);
				foreach ($sources as $src) {
					if ($src != "" ) {
						$q = "INSERT INTO PackageSources (PackageID, Source) VALUES (";
						$q .= $pdata["ID"] . ", '" . mysql_real_escape_string($src) . "')";
						db_query($q, $dbh);
					}
				}

				header('Location: packages.php?ID=' . $pdata['ID']);

			} else {
				$uid = uid_from_sid($_COOKIE["AURSID"]);

				# This is a brand new package
				$q = sprintf("INSERT INTO Packages (Name, License, Version, CategoryID, Description, URL, SubmittedTS, SubmitterUID, MaintainerUID, FSPath) VALUES ('%s', '%s', '%s-%s', %d, '%s', '%s', UNIX_TIMESTAMP(), %d, %d, '%s')",
					mysql_real_escape_string($new_pkgbuild['pkgname']),
					mysql_real_escape_string($new_pkgbuild['license']),
					mysql_real_escape_string($new_pkgbuild['pkgver']),
					mysql_real_escape_string($new_pkgbuild['pkgrel']),
					mysql_real_escape_string($_REQUEST['category']),
					mysql_real_escape_string($new_pkgbuild['pkgdesc']),
					mysql_real_escape_string($new_pkgbuild['url']),
					$uid,
					$uid,
					mysql_real_escape_string($incoming_pkgdir . "/" . $pkg_name . ".tar.gz"));

				$result = db_query($q, $dbh);
				$packageID = mysql_insert_id($dbh);

				# Update package depends
				$depends = explode(" ", $new_pkgbuild['depends']);
				foreach ($depends as $dep) {
					$q = "INSERT INTO PackageDepends (PackageID, DepPkgID, DepCondition) VALUES (";
					$deppkgname = preg_replace("/[<>]?=.*/", "", $dep);
					$depcondition = str_replace($deppkgname, "", $dep);

					if ($deppkgname == "#") {
						break;
					}

					$deppkgid = create_dummy($deppkgname, $_COOKIE['AURSID']);
					$q .= $packageID . ", " . $deppkgid . ", '" . mysql_real_escape_string($depcondition) . "')";

					db_query($q, $dbh);
				}

				# Insert sources
				$sources = explode(" ", $new_pkgbuild['source']);
				foreach ($sources as $src) {
					if ($src != "" ) {
						$q = "INSERT INTO PackageSources (PackageID, Source) VALUES (";
						$q .= $packageID . ", '" . mysql_real_escape_string($src) . "')";
						db_query($q, $dbh);
					}
				}

				pkg_notify(account_from_sid($_COOKIE["AURSID"]), array($packageID));

				header('Location: packages.php?ID=' . $packageID);

			}
		}

		chdir($cwd);
	}

# Logic over, let's do some output

html_header("Submit");

?>

<?php if ($error): ?>
	<p class="pkgoutput"><?php print $error ?></p>
<?php endif; ?>

<div class="pgbox">
	<div class="pgboxtitle">
		<span class="f3"><?php print __("Submit"); ?></span>
	</div>
	<div class="pgboxbody">
	<p><?php echo __("Upload your source packages here. Create source packages with `makepkg --source`.") ?></p>

<?php
	if (empty($_REQUEST['pkgsubmit']) || $error):
		# User is not uploading, or there were errors uploading - then
		# give the visitor the default upload form
		if (ini_get("file_uploads")):

			$pkg_categories = pkgCategories();
?>

<form action='pkgsubmit.php' method='post' enctype='multipart/form-data'>
	<div> <input type='hidden' name='pkgsubmit' value='1' /> </div>
	<table border='0' cellspacing='5'>
		<tr>
			<td class='f4' align='right'><?php print __("Package Category"); ?>:</td>
			<td class='f4' align='left'>
			<select name='category'>
				<option value='1'><?php print __("Select Category"); ?></option>
				<?php
					foreach ($pkg_categories as $num => $cat):
						print "<option value='" . $num . "'";
						if (isset($_POST['category']) && $_POST['category'] == $cat):
							print " selected='selected'";
						endif;
						print ">" . $cat . "</option>";
					endforeach;
				?>
			</select>
			</td>
		</tr>
		<tr>
			<td class='f4' align='right'><?php print __("Upload package file"); ?>:</td>
			<td class='f4' align='left'>
				<input type='file' name='pfile' size='30' />
			</td>
		</tr>
		<tr>
			<td align='left'>
				<input class='button' type='submit' value='<?php print __("Upload"); ?>' />
			</td>
		</tr>
	</table>
</form>

<?php
		else:
			print __("Sorry, uploads are not permitted by this server.");
?>

<br />

<?php
		endif;
	endif;
else:
	# Visitor is not logged in
	print __("You must create an account before you can upload packages.");
	exit();
?>

<br />

<?php
endif;
?>

	</div>
</div>

<?php
html_footer(AUR_VERSION);

