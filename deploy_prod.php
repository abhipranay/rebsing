<?php
### Script-Name:  deploy_prod.php
### Author     :  Kailash Chandak
### Purpose    :  This script is used to do the  deployment of the production one server and copy to the another server. Some globals are mentioned at the start of the file which needs to be present.
### Release   :  Adding the GIT functionality, GIT will add tags, so we dont need to have code folders for previous updates. Also the updater's info will be logged.

error_reporting(E_ALL ^ E_WARNING);

## PROD 2 server name and user
# We assume the SSH keys are setup and login is passowrdless

## make this $debug = 0 if all done
global $debug;
$debug = 1;


if($debug == 1){
$server_prod2 = 'coral.belzabar.com';
$user_prod2   = 'abhi';
$server_prod2_path = '/home/abhi/test'; ## Must be without trailing slash
}
else{
$server_prod2 = '10.176.161.82';
$user_prod2   = 'comptroller';
$server_prod2_path = '/var/www/html'; ## Must be without trailing slash
}


## GIT HUB Account Details
## We might not need it as we added the SSH keys and it works for it.

## Globals
global $enter_timestamp;
$enter_timestamp = true;

## Default Values to be used

## Log files will be created with date as extension
$log_file_name = "deployment_logs_" . date("Y-m-d");

##$log_file_path = '/home/comptroller/deployment_logs/';
$log_file_path = '/home/'. get_current_user() . '/deployment_logs/';





$log_file = $log_file_path . $log_file_name;

##[2014-04-08]: Now we need to add more parameters to log info about user
## Need to remove the already existing params too(existing-folder-path:, new-folder-path:, updated-code-folder-path:, link-path:)
## Define all the agruments
$user_o = 'user::';
$branch_o = 'branch::';
$release_type_o = 'release-type::';
$release_name_o = 'release-name::';
$prod_folder_path_o = 'prod-folder-path:';
$git_folder_path_o = 'git-folder-path:';
$deployment_notes_o = 'deployment-notes::';
$mail_id_o = 'mail-id::';

$longopts  = array(
    "$user_o",     // value(will be asked to user)
    "$branch_o",     // value(will be asked to user)
    "$release_type_o",  // value(will be asked to user)
    "$release_name_o",     // value(will be asked to user)
    "$prod_folder_path_o",     // Required value
    "$git_folder_path_o",   // Required value
    "$deployment_notes_o",   // value(will be asked to user)
    "$mail_id_o",   // value
    "help"   // value
);

## We need to rmeove the "::" or ":" from the var names, so we can use them later
$branch = preg_replace('/:/', "", $branch_o);
$release_name = preg_replace('/:/', "", $release_name_o);
$release_type = preg_replace('/:/', "", $release_type_o);
$deployment_notes = preg_replace('/:/', "", $deployment_notes_o);
$user = preg_replace('/:/', "", $user_o);
$git_folder_path = preg_replace('/:/', "", $git_folder_path_o);
$prod_folder_path = preg_replace('/:/', "", $prod_folder_path_o);

## Define the Needed options here, add to array
## These are the options which if user dont provide must be taken from him at run time
$needed_opts = array ($user, $branch, $deployment_notes);

## Define the dependents keys with key value
$dependent_opts = array (
		    $release_type => $release_name, 
		    $release_name => $release_type
		);

## Create mandatory field array
$mandatory_opts = array();
foreach ($longopts as $key)
{
    if(preg_match('/^[^:]*:$/', $key))
    {
	$key = preg_replace('/:/', '', $key);
	array_push($mandatory_opts, $key);
    }
}

## Get all the agruments
$options = getopt("", $longopts);

## If Help option is present, show help nd exit
if(isset($options['help']))
{
    show_help();
    exit(1);
}

## Check if logging folder is correct and writable
## If logging folder is doesn't exist or is not writable then abort script
if(!check_dir_writable($log_file_path)){
	echo "\nRerun the script\n";
	echo "\nAborting.......\n";
	exit(-1);
}

## Santize input
sanitize_options($options);

## Process input
process_options($options);

## Additional check to verify if user only wants to build with current code and no update
if(! isset($options[$release_type]) && ! isset($options[$release_name]))
{
    update_current_codebase();
}

## Lets fetch the updates from GITHUB for the mentioned branch and tag(if the tag is already present)
fetch_updates($options);

## Remove the last 30 commit log files
rotate_logs();

exit(0);

###################################### Below marks the definition area of our functions #########################################################################

## Below function sanitizes the options passed
function sanitize_options(&$options)
{
    if(!empty($options))
    {
	foreach( $options as $key => $value)
	{
	    ## Remove the trailing slash if present
	    $options[$key] = preg_replace('/\/$/', '', $value);

	    ## Remove the spaces
	    $options[$key] = trim($value);
	}
    }
    else
    {
	print "\nPlease enter the mandatory params, check the usage section with the --help option\n";
	exit(1);
    }
}

## Below function processes the command line options and create respective 
function process_options(&$options)
{
    global $mandatory_opts, $needed_opts;

    ## We need to validate some values like for ex: release-type and release-name
    foreach($options as $key => $value)
    {
	if(! validate_option_value($options, $key, $value))
	{
	    print "\nThe value: $value for the option: $key is not valid, please enter a valid value.\n\n";
	    exit(1);
	}
    }

    ## Check if the mandatory are given as options and have value in them
    if(! validate_mandatory_keys($mandatory_opts, $options)) 
    {
	find_missing_key($mandatory_opts, $options, 1);
    }

    foreach($options as $key => $value)
    {
	if($value === '')
	{
	    unset($options[$key]);
	}
    }

    ## Now call missing key, in case anything was missing from user
    find_missing_key($needed_opts, $options);
}

## Below function checks the keys if present in the array
function validate_mandatory_keys($keys, $array) 
{
    if (count (array_intersect($keys, array_keys($array))) == count($keys)) 
    {
        return true;
    }
    else 
    {
	return false;
    }
}

## Below function checks what all keys are missing from the  given options array
function find_missing_key($keys, &$options, $is_mandatory = 0)
{
    global $dependent_opts;

    ## Check what values are absent in options which are in keys
    $result = array_diff($keys, array_keys($options));
    
    if(count($result) > 0)
    {
	## Check if any of the dependent key is there but no value, add the value in array too
	foreach( $dependent_opts as $key => $value)
	{
	    $key = preg_replace('/:/', '', $key);
	    $value = preg_replace('/:/', '', $value);

	    if(isset($options[$key]) && ! isset($options[$value]))
	    {
		array_push($result, $value);
	    }
	}

	## If Mandatory, inform user and exit
	if($is_mandatory == 1)
	{
	    print "\nPlease provide the value for mandatory options(missing options are:" . implode(", ", $result) ."), check usage section with the --help option\n\n";
	    exit(1);
	}
	else
	{
	    ## Prompt User to enter values for the keys
	    $FH = fopen( 'php://stdin', 'r' );

	    foreach($result as $key)
	    {
		$key = preg_replace('/:+/', '', $key);
		$response = '';
		$flag = 0;
		while($response === '')
		{
		    if($flag == 1)
		    {
			print "Entered value is not accepted for the given option.\n";
		    }
		    print "Please enter $key:";
		    $response = trim(fgets($FH));
		    if(! validate_option_value($options, $key, $response))
		    {
			$response = '';
		    }
		    $flag = 1;
		}
		$options[$key] = $response;
	    }
	}
    }
}

## Below will print the usage of the script
function show_help()
{
    echo "";
}

## Below function validates option values
## Please add the code here to do validation for any input required from user which must be checked for allowed ranges or values
function validate_option_value(&$options, $key, $value)
{
    global $release_type, $release_name;
    $release_type = preg_replace('/:/', '', $release_type);
    $release_name = preg_replace('/:/', '', $release_name);

    if(preg_match("/$key/", $release_type))
    {
	if((preg_match('/major/i', $value) || preg_match('/minor/i', $value)))
	{
	    return true;
	}
	else
	{
	    return false;
	}
    }
    elseif(preg_match("/$key/", $release_name))
    {
	if(preg_match('/^v\d+?\.0$/', $value) && preg_match('/major/i', $options[$release_type]))
	{
	    return true;
	}
	elseif(preg_match('/^v\d+?\.\d+$/', $value) && preg_match('/minor/i', $options[$release_type]))
	{
	    return true;
	}
	else
	{
	    return false;
	}
    }
    else
    {
	return true;
    }
}

## Below function updates the current codebase if user wants to do so
function update_current_codebase()
{
    ## We need to build from existing codebase and log it
    $response = getInput("\nRelease Name and Release Type are not present, build will be done from current codebase only, you want to continue[y/n]:");
    if($response === 'y' || $response === 'Y'|| preg_match('/yes/i', $response))
    {
	print "\nBuilding from existing codebase as Release Name and Release Type are not present ...\n";

	$update_status_prod1 = copy_codebase();
	if($update_status_prod1 === 'Successful')
	{
	    $update_status_prod2 = push_code_prod2();
	    if($update_status_prod2 !== 'Successful')
	    {
		logger($update_status_prod1, $update_status_prod2);
		exit(1);
	    }
	}
	else
	{
	    logger($update_status_prod1, $update_status_prod2);
	    exit(1);
	}

	## You are Done, PROD is UP to DATE
	print "\nProduction instances updated Successfully. Thank you for using the tool.\n\n";

	exit(0);
    }
    else
    {
	print "Exiting the process\n";
	exit(0);
    }
}

## Below function will fetch the code from GITHUB from the specified branch and rev number(if present on GITHUB)
function fetch_updates($options)
{
    global $branch, $release_name, $release_type, $deployment_notes, $user, $git_folder_path, $prod_folder_path;

    $update_status_prod1;
    $update_status_prod2;

    $branch = preg_replace('/:/', "", $branch);
    $release_name = preg_replace('/:/', "", $release_name);
    $release_type = preg_replace('/:/', "", $release_type);
    $deployment_notes = preg_replace('/:/', "", $deployment_notes);
    $user = preg_replace('/:/', "", $user);
    $git_folder_path = preg_replace('/:/', "", $git_folder_path);
    $prod_folder_path = preg_replace('/:/', "", $prod_folder_path);

    ## Take the branch
    $branch = $options[$branch];

    ## Take the rev number
    $rev_number = $options[$release_name];

    ## Lets first pull from the GIT HUB, for that we need to be in the GIT HUB repo directory
    $output = system("pwd");
    $working_dir = $output;
    ##$output = system("cd $options[$git_folder_path]", $retval);
    $retval = chdir($options[$git_folder_path]);
    if(!$retval)
    {
	print "\nChanging to GIT Repo dir failed: $output\n";
	print "Please rerun the script with valid git  hub repo path\n";
	$output = system("cd $working_dir", $retval);
	exit(1);
    }
    else
    {
	print "\nChanged to GIT Repo Dir ...\n";
	print "\nPulling the changes from GIT HUB REPO to Local Dir ...\n";

	## Now, we need to: 1) DO git fetch origin, so data is updated. 2) Try to fetch the requested branch.
	##$output = system("git fetch", $retval);
	$output = array();
	exec('git fetch', $output, $retval);

	#$output = system("git checkout $branch");
	$output = array();
	exec("git checkout $branch", $output, $retval);

	if($retval)
	{
	    print "\nUnable to Fetch the requested branch: $branch, Reason: ". implode(PHP_EOL, $output). "\n";
	    print "Please rerun the script with valid git hub branch\n";
	    $output = system("cd $working_dir", $retval);
	    exit(1);
	}
	else
	{
	    print "\nChecked-out the branch: $branch ...\n";
	    print "\nPulling the updated code in $branch ...\n";

	    ## Pull with GIT
	    #$output = system("git pull origin", $retval);
	    $output = array();
	    exec('git pull origin', $output, $retval);
	    
	    if($retval)
	    {
		print "\nUnable to Pull the code from GIT Repo, Reason: ". implode(PHP_EOL, $output). "\n";
		print "Please rerun the script to try again.\n";
		$output = system("cd $working_dir", $retval);
		exit(1);
	    }
	    else
	    {
		print "\nPull Output: ". implode(PHP_EOL, $output). "\n";

		$response = getInput("Do you want to continue updating the codebase on production instance(y/n):");
		if($response === 'y' || preg_match('/yes/i', $response))
		{
		    print "\nChecking the release number ...\n";
		    
		    ## Get all the release tags data 
		    #$output = system("git tag", $retval);
		    $output = array();
		    exec('git tag', $output, $retval);

		    $output = implode(", ", $output);
		    if(preg_match("/$options[$release_name]/i", $output))
		    {
			## Check if the given tag is the current state tag or prev tag
			#$output = system("git describe", $retval);
			$output = array();
			exec("git describe", $output, $retval);

			if($output[0] === $options[$release_name])
			{
			    print "\nCurrent release number and your release number are same, release number cannot be updated as it already exists\n";
			    print "Please provide new release number and rerun script to update\n\n";
			    $output = system("cd $working_dir", $retval);
			    exit(1);
			}
			else
			{
			    print "\nYour release number is an pre release version number, if you continue codebase might be reverted.\n";
			    $response = getInput("Are you sure you want to continue[y/n]:");
			    if($response === 'y' || preg_match('/yes/i', $response))
			    {
				#$output = system("git reset --hard $options[$release_name]", $retval);
				$output = array();
				exec("git reset --hard $options[$release_name]", $output, $retval);

				if($retval)
				{
				    print "\nRevert to previous version failed, Reason: ". implode(PHP_EOL, $output). "\n";
				    print "Please try again, exiting.\n";
				    $output = system("cd $working_dir", $retval);
				    exit(1);
				}
				{
				    $update_status_prod1 = copy_codebase();

				    ## Now we need to push the code base to Production machine 2
				    if($update_status_prod1 === 'Successful')
				    {
					$update_status_prod2 = push_code_prod2();
					if($update_status_prod2 !== 'Successful')
					{
					    ## Log information to the log file
					    logger($update_status_prod1, $update_status_prod2);
					    $output = system("cd $working_dir", $retval);
					    exit(1);
					}
				    }
				    else
				    {
					$update_status_prod2 = "Prod1 failed, Prod2 skipped";
				    }

				    ## Log information to the log file
				    logger($update_status_prod1, $update_status_prod2);

				    ## You are Done, PROD is UP to DATE
				    print "\nProduction instances updated Successfully. Thank you for using the tool.\n\n";
				}
			    }
			    else
			    {
				print "\nExiting the process, please try again.\n";
				$output = system("cd $working_dir", $retval);
				exit(0);
			    }
			}
		    }
		    else
		    {
			$update_status_prod1 = copy_codebase();

			if($update_status_prod1 === 'Successful')
			{
			    print "\nAdding the recent tag to GIT HUB Repo ...\n";
			    #$output = system("git tag -a $options[$release_name] -m '$options[$deployment_notes]'", $retval);
			    $output = array();
			    exec("git tag -a $options[$release_name] -m '$options[$deployment_notes]'", $output, $retval);

			    print "\nPushing the tag information to GIT HUB Repo ...\n";
			    #$output = system("git push origin $options[$release_name]", $retval);
			    $output = array();
			    exec("git push origin --tag", $output, $retval);

			    print "\nTag information pushed to GIT HUB Repo.\n";

			    ## Now we need to push the code base to Production machine 2
			    print "\nNow updating PROD 2 ...\n";
			    $update_status_prod2 = push_code_prod2();
			    if($update_status_prod2 !== "Successful")
			    {
				## Log information to the log file
				logger($update_status_prod1, $update_status_prod2);
				$output = system("cd $working_dir", $retval);
				exit(1);
			    }
			}
			else
			{
			    $update_status_prod2 = "Prod1 failed, Prod2 skipped";
			}

			## Log information to the log file
			logger($update_status_prod1, $update_status_prod2);

			## You are Done, PROD is UP to DATE
			print "\nProduction instances updated Successfully. Thank you for using the tool.\n\n";
		    }
		}
		else
		{
		    $update_status_prod1 = "Aborted by user after PULL from GIT HUB Repo";
		    $update_status_prod2 = "Aborted by user after PULL from GIT HUB Repo";
		    logger($update_status_prod1, $update_status_prod2);
		    print "Exiting.\n";
		}
	    }
	}
    }
$output = system("cd $working_dir", $retval);    
}

## Below gets user input from STDIN
function getInput($msg)
{
  fwrite(STDOUT, "$msg: ");
  $varin = trim(fgets(STDIN));
  return $varin;
}

## Below function copies the files from the git_folder_path to prod_folder_path
function copy_codebase()
{
    global $options, $git_folder_path, $prod_folder_path , $debug;
    $git_folder_path = preg_replace('/:/', "", $git_folder_path);
    $prod_folder_path = preg_replace('/:/', "", $prod_folder_path);

    ## Now copy the updated contents to new directory
    print "Copying the files from $options[$git_folder_path] to $options[$prod_folder_path]/wp-content/ ...\n";

    $output = array();
    #$output = system("cp -r $options[$git_folder_path]/plugins $options[$git_folder_path]/themes $options[$prod_folder_path]/wp-content/", $retval);
    if($debug == 1){
		exec("rsync -avz --exclude '.git' $options[$git_folder_path] $options[$git_folder_path] $options[$prod_folder_path]", $output, $retval);
	}
	else{
		exec("rsync -avz --exclude '.git' $options[$git_folder_path]/plugins $options[$git_folder_path]/themes $options[$prod_folder_path]/wp-content/", $output, $retval);
	}

    if($retval)
    {
	print "\nError: Copy of contents failed to directory path: $options[$git_folder_path]. Reason:\n";
	print implode("\n", $output);
	return "Failed";

    }
    else
    {
	echo "\nUpdated code added to $options[$prod_folder_path]\n";
	return "Successful";
    }
}

## Below function pushes the updated code to prod 2 machine
function push_code_prod2()
{
    global $options, $prod_folder_path, $user_prod2, $server_prod2, $server_prod2_path , $debug;

	if($debug == 1){
		$folder_path = $options[$prod_folder_path]."/";
	}
	else{
		$folder_path = $options[$prod_folder_path]."/wp-content/";
	}

    $cmd = "rsync -ave ssh $folder_path $user_prod2" . '@'. $server_prod2 . ':' . $server_prod2_path;

    exec($cmd, $output, $retval);

    if($retval)
    {
	print "\nError: Code Push to $server_prod2 failed. Reason:\n";
	print implode("\n", $output);
	return "Failed";
    }
    else
    {
	return "Successful";
    }
}

## Logger function to log the values
function logger($update_status_prod1, $update_status_prod2)
{
    global $options, $branch, $release_name, $release_type, $deployment_notes, $user, $git_folder_path, $prod_folder_path, $enter_timestamp;

    global $log_file;

    $time = "############################### ". date('D, d-M-Y H:i:s') . " ###############################\n";

    $data = $time;
    $data .= "Name of Deployer   : $options[$user]\n";
    $data .= "Deployer Notes     : $options[$deployment_notes]\n";
    $data .= "Deployment Time    : ".date('D, d-M-Y H:i:s')."\n";
    $data .= "Release Type       : $options[$release_type]\n";
    $data .= "Git Release Number : $options[$release_name]\n";
    $data .= "Deployment Status  => \n";
    $data .= "PROD Machine 1     : $update_status_prod1\n";
    $data .= "PROD Machine 2     : $update_status_prod2\n\n";

    file_put_contents($log_file, $data, FILE_APPEND | LOCK_EX);
}

## Below fucntion will be used to rotate the log files based on some value
function rotate_logs()
{
}

## Below function checks if a dir exists with writable permissions
function check_dir_writable($dir_path){
	if(is_dir($dir_path)){
		echo "\n$dir_path Exists\n";
	}
	else{
		echo "\n$dir_path Does Not Exists !!!\n";
		echo "\nCreate $dir_path\n";
		return false;
	}
	if(is_writable($dir_path)){
		echo "\n$dir_path have write permissions\n";
	}
	else{
		echo "\n$dir_path Does Not have write permissions !!!\n";
		echo "\nMake $dir_path writable\n";
		return false;
	}
return true;	
}
?>
