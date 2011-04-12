#!/usr/bin/python2
"""
usage: gendummydata.py outputfilename.sql
"""
#
# This script seeds the AUR database with dummy data for
# use during development/testing.  It uses random entries
# from /usr/share/dict/words to create user accounts and
# package names.  It generates the SQL statements to
# insert these users/packages into the AUR database.
#
import random
import time
import os
import sys
import cStringIO
import commands
import logging

LOG_LEVEL = logging.DEBUG # logging level. set to logging.INFO to reduce output
SEED_FILE = "/usr/share/dict/words"
DB_HOST   = os.getenv("DB_HOST", "localhost")
DB_NAME   = os.getenv("DB_NAME", "AUR")
DB_USER   = os.getenv("DB_USER", "aur")
DB_PASS   = os.getenv("DB_PASS", "aur")
USER_ID   = 5          # Users.ID of first bogus user
PKG_ID    = 1          # Packages.ID of first package
MAX_USERS = 300        # how many users to 'register'
MAX_DEVS  = .1         # what percentage of MAX_USERS are Developers
MAX_TUS   = .2         # what percentage of MAX_USERS are Trusted Users
MAX_PKGS  = 900       # how many packages to load
PKG_FILES = (8, 30)    # min/max number of files in a package
PKG_DEPS  = (1, 5)     # min/max depends a package has
PKG_SRC   = (1, 3)     # min/max sources a package has
PKG_CMNTS = (1, 5)     # min/max number of comments a package has
CATEGORIES_COUNT = 17  # the number of categories from aur-schema
VOTING    = (0, .30)   # percentage range for package voting
RANDOM_PATHS = (       # random path locations for package files
	"/usr/bin", "/usr/lib", "/etc", "/etc/rc.d", "/usr/share", "/lib",
	"/var/spool", "/var/log", "/usr/sbin", "/opt", "/usr/X11R6/bin",
	"/usr/X11R6/lib", "/usr/libexec", "/usr/man/man1", "/usr/man/man3",
	"/usr/man/man5", "/usr/X11R6/man/man1", "/etc/profile.d"
)
RANDOM_TLDS = ("edu", "com", "org", "net", "tw", "ru", "pl", "de", "es")
RANDOM_URL = ("http://www.", "ftp://ftp.", "http://", "ftp://")
RANDOM_LOCS = ("pub", "release", "files", "downloads", "src")
FORTUNE_CMD = "/usr/bin/fortune -l"

# setup logging
logformat = "%(levelname)s: %(message)s"
logging.basicConfig(format=logformat, level=LOG_LEVEL)
log = logging.getLogger()

if len(sys.argv) != 2:
	log.error("Missing output filename argument")
	raise SystemExit

# make sure the seed file exists
#
if not os.path.exists(SEED_FILE):
	log.error("Please install the 'words' Arch package")
	raise SystemExit

# track what users/package names have been used
#
seen_users = {}
seen_pkgs = {}
user_keys = []

# some functions to generate random data
#
def genVersion():
	ver = []
	ver.append("%d" % random.randrange(0,10))
	ver.append("%d" % random.randrange(0,20))
	if random.randrange(0,2) == 0:
		ver.append("%d" % random.randrange(0,100))
	return ".".join(ver) + "-u%d" % random.randrange(1,11)
def genCategory():
	return random.randrange(1,CATEGORIES_COUNT)
def genUID():
	return seen_users[user_keys[random.randrange(0,len(user_keys))]]


# load the words, and make sure there are enough words for users/pkgs
#
log.debug("Grabbing words from seed file...")
fp = open(SEED_FILE, "r")
contents = fp.readlines()
fp.close()
if MAX_USERS > len(contents):
	MAX_USERS = len(contents)
if MAX_PKGS > len(contents):
	MAX_PKGS = len(contents)
if len(contents) - MAX_USERS > MAX_PKGS:
	need_dupes = 0
else:
	need_dupes = 1

# select random usernames
#
log.debug("Generating random user names...")
user_id = USER_ID
while len(seen_users) < MAX_USERS:
	user = random.randrange(0, len(contents))
	word = contents[user].replace("'", "").replace(".","").replace(" ", "_")
	word = word.strip().lower()
	if not seen_users.has_key(word):
		seen_users[word] = user_id
		user_id += 1
user_keys = seen_users.keys()

# select random package names
#
log.debug("Generating random package names...")
num_pkgs = PKG_ID
while len(seen_pkgs) < MAX_PKGS:
	pkg = random.randrange(0, len(contents))
	word = contents[pkg].replace("'", "").replace(".","").replace(" ", "_")
	word = word.strip().lower()
	if not need_dupes:
		if not seen_pkgs.has_key(word) and not seen_users.has_key(word):
			seen_pkgs[word] = num_pkgs
			num_pkgs += 1
	else:
		if not seen_pkgs.has_key(word):
			seen_pkgs[word] = num_pkgs
			num_pkgs += 1

# free up contents memory
#
contents = None

# developer/tu IDs
#
developers = []
trustedusers = []
has_devs = 0
has_tus = 0

# Just let python throw the errors if any happen
#
out = open(sys.argv[1], "w")
out.write("BEGIN;\n")

# Begin by creating the User statements
#
log.debug("Creating SQL statements for users.")
for u in user_keys:
	account_type = 1  # default to normal user
	if not has_devs or not has_tus:
		account_type = random.randrange(1, 4)
		if account_type == 3 and not has_devs:
			# this will be a dev account
			#
			developers.append(seen_users[u])
			if len(developers) >= MAX_DEVS * MAX_USERS:
				has_devs = 1
		elif account_type == 2 and not has_tus:
			# this will be a trusted user account
			#
			trustedusers.append(seen_users[u])
			if len(trustedusers) >= MAX_TUS * MAX_USERS:
				has_tus = 1
		else:
			# a normal user account
			#
			pass

	s = ("INSERT INTO Users (ID, AccountTypeID, Username, Email, Passwd)"
		 " VALUES (%d, %d, '%s', '%s@example.com', MD5('%s'));\n")
	s = s % (seen_users[u], account_type, u, u, u)
	out.write(s)

log.debug("Number of developers: %d" % len(developers))
log.debug("Number of trusted users: %d" % len(trustedusers))
log.debug("Number of users: %d" % (MAX_USERS-len(developers)-len(trustedusers)))
log.debug("Number of packages: %d" % MAX_PKGS)

# Create the package statements
#
log.debug("Creating SQL statements for packages.")
count = 0
for p in seen_pkgs.keys():
	NOW = int(time.time())
	if count % 2 == 0:
		muid = developers[random.randrange(0,len(developers))]
	else:
		muid = trustedusers[random.randrange(0,len(trustedusers))]
	if count % 20 == 0: # every so often, there are orphans...
		muid = 0

	uuid = genUID() # the submitter/user

	if muid == 0:
		s = ("INSERT INTO Packages (ID, Name, Version, CategoryID,"
			 " SubmittedTS, SubmitterUID, MaintainerUID) VALUES"
			 " (%d, '%s', '%s', %d, %d, %d, NULL);\n")
		s = s % (seen_pkgs[p], p, genVersion(), genCategory(), NOW, uuid)
	else:
		s = ("INSERT INTO Packages (ID, Name, Version, CategoryID,"
			 " SubmittedTS, SubmitterUID, MaintainerUID) VALUES "
			 " (%d, '%s', '%s', %d, %d, %d, %d);\n")
		s = s % (seen_pkgs[p], p, genVersion(), genCategory(), NOW, uuid, muid)

	out.write(s)
	count += 1

	# create random comments for this package
	#
	num_comments = random.randrange(PKG_CMNTS[0], PKG_CMNTS[1])
	for i in range(0, num_comments):
		fortune = commands.getoutput(FORTUNE_CMD).replace("'","")
		now = NOW + random.randrange(400, 86400*3)
		s = ("INSERT INTO PackageComments (PackageID, UsersID,"
			 " Comments, CommentTS) VALUES (%d, %d, '%s', %d);\n")
		s = s % (seen_pkgs[p], genUID(), fortune, now)
		out.write(s)

# Cast votes
#
track_votes = {}
log.debug("Casting votes for packages.")
for u in user_keys:
	num_votes = random.randrange(int(len(seen_pkgs)*VOTING[0]),
			int(len(seen_pkgs)*VOTING[1]))
	pkgvote = {}
	for v in range(num_votes):
		pkg = random.randrange(1, len(seen_pkgs) + 1)
		if not pkgvote.has_key(pkg):
			s = ("INSERT INTO PackageVotes (UsersID, PackageID)"
				 " VALUES (%d, %d);\n")
			s = s % (seen_users[u], pkg)
			pkgvote[pkg] = 1
			if not track_votes.has_key(pkg):
				track_votes[pkg] = 0
			track_votes[pkg] += 1
			out.write(s)

# Update statements for package votes
#
for p in track_votes.keys():
	s = "UPDATE Packages SET NumVotes = %d WHERE ID = %d;\n"
	s = s % (track_votes[p], p)
	out.write(s)

# Create package dependencies and sources
#
log.debug("Creating statements for package depends/sources.")
for p in seen_pkgs.keys():
	num_deps = random.randrange(PKG_DEPS[0], PKG_DEPS[1])
	this_deps = {}
	i = 0
	while i != num_deps:
		dep = random.choice([k for k in seen_pkgs])
		if not this_deps.has_key(dep):
			s = "INSERT INTO PackageDepends VALUES (%d, '%s', NULL);\n"
			s = s % (seen_pkgs[p], dep)
			out.write(s)
			i += 1

	num_sources = random.randrange(PKG_SRC[0], PKG_SRC[1])
	for i in range(num_sources):
		src_file = user_keys[random.randrange(0, len(user_keys))]
		src = "%s%s.%s/%s/%s-%s.tar.gz" % (
				RANDOM_URL[random.randrange(0,len(RANDOM_URL))],
				p, RANDOM_TLDS[random.randrange(0,len(RANDOM_TLDS))],
				RANDOM_LOCS[random.randrange(0,len(RANDOM_LOCS))],
				src_file, genVersion())
		s = "INSERT INTO PackageSources VALUES (%d, '%s');\n"
		s = s % (seen_pkgs[p], src)
		out.write(s)

# close output file
#
out.write("COMMIT;\n")
out.write("\n")
out.close()
log.debug("Done.")
