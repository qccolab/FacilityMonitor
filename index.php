<?php
// HACKING HINT: Replace getConnectedMachines() with a function that returns an array of the MACs connected
// to your wireless router.

// ************* Edit the Following Values to Match your Install ******************
/// MOTD
$MOTD = "<h2>Welcome to the QC Co-Lab!<h2> <h3>The following users are in residence:<h3>";
/// The host of the Mikrotik router
$HOST = "172.27.72.1";
/// The community string to use when accessing the router
$COMMUNITY = "public";
/// The object ID we'll walk in order to enumerate the connected machines
$OBJECT_ID = "iso.3.6.1.4.1.14988.1.1.1.2.1.3";
/// Directory name to store the database.  Must be writeable by webserver.
$DBNAME = "/srv/FacilityMonitor";
/// Path to arp
$ARP = "/usr/sbin/arp";
/// Path to awk
$AWK = "/usr/bin/awk";

// ************ Global Variables, don't edit these unless you're programming ******
/// The db connection object
$db = "";


// ********************* Functions ************************************************

/***
 * Accesses the Microtik router and downloads a list of machines connected to the wireless.
 * @return An array of MAC addresses
 */
function getConnectedMachines() {
    global $HOST, $COMMUNITY, $OBJECT_ID;
  
    $objs = snmprealwalk($HOST, $COMMUNITY, $OBJECT_ID);
    foreach ($objs as $key => $val) {
        // The mac addresses are encoded in the object name, extract them
        $key = preg_replace("/$OBJECT_ID\.(.*)\.6/", '${1}', $key);
        $octets = explode(".", $key);
        $ret[] = sprintf("%02x:%02x:%02x:%02x:%02x:%02x", $octets[0], $octets[1], $octets[2], $octets[3], $octets[4], $octets[5]);
        }
    return $ret;
    }

/***
 * Connects to the database, creates it or upgrades it if requred. 
 */
function connectDB() {
    global $DBNAME, $db;

    if (!file_exists("$DBNAME/main.db")) {
        $ret = touch("$DBNAME/main.db"); 
        if (!$ret)
            die("Could not create database file $DBNAME/main.db");
        }
  
    try {
        $db = new PDO("sqlite:$DBNAME/main.db"); 
        
        $res = $db->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users';");
        if ($res->fetchColumn() == 0)
            {
            echo("Setting up new database<br>");
            $ret = $db->query("create table users (mac text primary key, string text, password text);");
            if ($ret === false)
                die("Problem creating users table".print_r($db->errorInfo()));
            }
        
        } catch(Exception $e) {
            die("Exception during database setup: ".$e->getMessage());
        }
    }

/***
 * @param[mac] A mac address to look up in the database
 * @return The string registered to this machine or boolean false if unregistered
 */
function retrieveString($mac) {
    global $db;
    
    $qmac = $db->quote($mac);
    
    $rows = $db->query("select count(*) from users where mac = $qmac;");
    if ($rows->fetchColumn() == 0) 
        return(false);

    $rows = $db->query("select * from users where mac = $qmac;")->fetch(PDO::FETCH_ASSOC);
    return($rows['string']);
    }

/**
 * Looks up the remote IP in the local arp table
 * @return Returns the mac address associated with the current mac
 */
function resolveRemoteMac() {
    global $AWK, $ARP;
    
    return exec("$ARP -n | $AWK '$1==\"".$_SERVER["REMOTE_ADDR"]."\"{print($3)}'");
    }

/***
 * This function registers a new user.
 * @param[mac] The mac of this machine
 */
function register($mac) {
    global $db;
    
    if ($_POST['password'] != $_POST['password2'])
     die("Passwords do not match");
     
    if ($mac == "")
     die("Can't register from the wired network");
     
    $string = $db->quote($_POST['string']);
    $password = $db->quote($_POST['password']); 
    $qmac = $db->quote($mac);
    
    // If it exists and the password does not match and the password isn't black, error out
    $rows = $db->query("select count(*) from users where mac like $qmac and password != '' and password != $password;");
    if ($rows->fetchColumn() > 0)
        {
        sleep(2);
        die("Invalid Password");
        }
    
    // Delete the row (Ignore error if it doesn't exist)
    $ret = $db->exec("delete from users where mac like $qmac;");
    if ($ret === false)
    	die("Problem writing to database".print_r($db->errorInfo()));
    
    // Add the row
    $db->exec("insert into users (mac, string, password) values($qmac, $string, $password);");
    }

?>
<html>
<head>
<link rel="stylesheet" type="text/css" media="all" href="http://www.qccolab.com/wp-content/uploads/montezuma/style.css" />
</head>
<body>

<?
connectDB();
$mac = resolveRemoteMac();
if (isset($_POST['register']))
    register($mac);

echo("$MOTD<BR><BR>");
$ret = getConnectedMachines();
$otherUsers = 0;
foreach ($ret as $key => $val) {
    $string = retrieveString($val);
    if ($string === false) {
         $otherUsers++;
         continue;
         }       

    if ($string != "ignoreme")
        echo $string."<br>";
    }
    
echo("<br>And $otherUsers unregistered users.<br><br>");

echo("If you're on the local network, and you own the client machine you're accessing this system from");
echo(" you may register it as an arbitrary string.  You may use any string you like but please do not be crude.");
echo(" The password field is optional, and will prevent future users from changing the registration of this machine.");
echo(" Register the machine as 'ignoreme' to prevent it's inclusion in the list of strings or in the count of unregistered machines.");

if ("$mac" != "")
 {
 echo("<br><br>This client is currently registered as: ");
 echo(retrieveString($mac));
 echo("<br><br>");
?>
<form action="index.php" method="post">
<table>
<tr><td><font>String:</td><td><input name=string size=100><br></td></tr>
<tr><td><font>Password:</td><td><input name=password type=password></td></tr>
<tr><td><font>Password:</td><td><input name=password2 type=password></td></tr>
<tr><td><input type=submit name=register>
</table>
</form>
</body>
</html>
<?php } ?>