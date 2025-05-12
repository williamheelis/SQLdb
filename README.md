## SQLdb

MySQL simple PHP client

`composer require williamheelis/sqldb`

## config like this

make a dir in your root `.SQLdb/` and in it make a `dbconfig.ini` file that looks like this

```php
[database]
host = localhost
username = myuser
password = mypass
database = mydb
```

IMPORTANT: make sure that `.sqldb\` is excluded in .gitignore

## use like this

get one row

```php
use Restful\SQLdb;

$db = new SQLdb();
$db->put($email,$dob);
$User = $db->queryrow("SELECT * FROM users WHERE email=? AND date_of_birth=? ORDER BY email LIMIT 0,1");
echo $User['lastName'] . "<br />"'
```

get a loop

```php
use Restful\SQLdb;

$db = new SQLdb();
$db->put($last_name);
$db->query("SELECT * FROM users WHERE lastName=?");
while ($tmp = $db->getnextrow()){
    echo $tmp['email'] . "<br />";
}
```

get one element

```php
use Restful\SQLdb;

$db = new SQLdb();
$db->put($id);
echo $db->quickquery("SELECT lastName FROM users WHERE id=?") . "<br />";

```
