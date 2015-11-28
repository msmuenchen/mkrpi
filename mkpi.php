#!/usr/bin/env php
<?php
/*
usage: ./mkpi.php [dist=stretch] [mirror="http://mirrordirector.raspbian.org/raspbian"] [lang=en_US]

create a truly minimal raspbian image with only the most neccessary software

this includes openssh, ntpdate and the common wireless tools

needs the following packages: coreutils kpartx e2fsprogs dosfstools mount tar util-linux debootstrap qemu-user-static

if lang is not en_US, it will install some extra packages so you can configure the system locale when you boot the system

future versions may autoconfigure the system based on this value

there will be no other user accounts than root:root. please change the password or you will get pwned

you may customize sources/wpa_supplicant.conf if you want to pre-provision your image with working wireless if you are running a headless pi
*/

$dist=isset($argv[1]) ? $argv[1]:"stretch";
$mirror=isset($argv[2]) ? $argv[2] : "http://mirrordirector.raspbian.org/raspbian";
$lang=isset($argv[3]) ? $argv[3] : "en_US";

$pkgs=[
  "wpasupplicant",
  "iw",
  "wireless-tools",
  "bash-completion",
  "libraspberrypi0",
  "libraspberrypi-bin",
  "libraspberrypi-dev",
  "raspberrypi-bootloader",
  "linux-image-rpi",
  "raspi-config",
  "openssh-server",
  "ntpdate",
  "firmware-linux",
  "firmware-atheros",
  "firmware-cavium",
  "firmware-brcm80211",
  "firmware-libertas",
  "firmware-realtek",
  "firmware-ti-connectivity",
  "firmware-zd1211",
  "firmware-misc-nonfree",
  "atmel-firmware",
];

if($lang!="en_US") {
  $pkgs+=[
    "console-data",
    "console-tools",
    "console-setup",
    "tzdata",
    "keyboard-configuration",
  ];
}

if(php_sapi_name()!="cli")
  die("not cli");

//bind-mount neccessary stuff if needed
function do_mount($path) {
  foreach(["/dev","/dev/pts","/sys","/proc","/tmp"] as $mountpoint) {
    $full=$path.$mountpoint;
    printf("Checking %s\n",$full);
    exec("mountpoint -q ".escapeshellarg($full),$out,$rc);
    if($rc==0)
      continue;
    check_exec("mount $mountpoint $full -o bind");
  }
}

//unmount the binds
function do_unmount($path) {
  foreach(array_reverse(["/dev","/dev/pts","/sys","/proc","/tmp"]) as $mountpoint) {
    $full=$path.$mountpoint;
    printf("Checking %s\n",$full);
    exec("mountpoint -q ".escapeshellarg($full),$out,$rc);
    if($rc==1)
      continue;
    check_exec("umount $full");
  }
}

//execute a program, bail if fails
function check_exec($cmd) {
  printf("Executing: %s\n",$cmd);
  exec($cmd,$out,$rc);
  if($rc==0)
    return $out;
  printf("Command failed\n");
  foreach($out as $line)
    printf("%s\n",trim($line));
  exit(1);
}

list($usec, $sec) = explode(" ", microtime());
$mt="$sec.$usec";

printf("Creating image for %s using primary mirror %s\n",$dist,$mirror);

printf("Creating initial chroot\n");
check_exec("debootstrap --no-check-gpg --foreign --arch armhf ${dist} root_${dist}_${mt}/ ".escapeshellarg($mirror));

printf("Copying qemu-arm-static\n");
check_exec("cp /usr/bin/qemu-arm-static root_${dist}_${mt}/usr/bin/");

printf("Mounting needed virtual filesystems\n");
do_mount("root_${dist}_${mt}");

printf("Finalizing chroot\n");
check_exec("cp sources/policy-rc.d root_${dist}_${mt}/etc/");
check_exec("chroot root_${dist}_${mt} /debootstrap/debootstrap --second-stage");
do_mount("root_${dist}_${mt}");

printf("Configuring base services\n");
check_exec("cp sources/sources.list root_${dist}_${mt}/etc/apt/");
check_exec("cp sources/fstab root_${dist}_${mt}/etc/");
check_exec("cp sources/interfaces root_${dist}_${mt}/etc/network/");
check_exec("cp sources/lo root_${dist}_${mt}/etc/network/interfaces.d/");
check_exec("cp sources/eth0 root_${dist}_${mt}/etc/network/interfaces.d/");
check_exec("cp sources/wlan0 root_${dist}_${mt}/etc/network/interfaces.d/");
check_exec("cp sources/cmdline.txt root_${dist}_${mt}/boot/");
check_exec("cp sources/config.txt root_${dist}_${mt}/boot/");
check_exec("mkdir -p root_${dist}_${mt}/etc/wpa_supplicant/");
check_exec("cp sources/wpa_supplicant.conf root_${dist}_${mt}/etc/wpa_supplicant/");
check_exec("cp sources/hostname root_${dist}_${mt}/etc/");

printf("Installing gpg public keys\n");
check_exec("cp sources/raspberrypi.gpg.key root_${dist}_${mt}/tmp/");
check_exec("cp sources/raspbian.gpg.key root_${dist}_${mt}/tmp/");
check_exec("chroot root_${dist}_${mt} apt-key add /tmp/raspberrypi.gpg.key");
check_exec("chroot root_${dist}_${mt} apt-key add /tmp/raspbian.gpg.key");
check_exec("rm root_${dist}_${mt}/tmp/raspberrypi.gpg.key");
check_exec("rm root_${dist}_${mt}/tmp/raspbian.gpg.key");

printf("Updating apt sources\n");
check_exec("chroot root_${dist}_${mt} apt-get update");

printf("Installing some extra packages\n");
check_exec("chroot root_${dist}_${mt} apt-get -o Dpkg::Options::=\"--force-confdef\" -o Dpkg::Options::=\"--force-confold\" -y install ".implode(" ",$pkgs));
check_exec("chroot root_${dist}_${mt} apt-get clean");

sleep(5); //do not remove this
check_exec("chroot root_${dist}_${mt}/ /etc/init.d/triggerhappy stop"); //or this

printf("Setting root password to root:root (WARNING: CHANGE ME)\n");
check_exec("echo 'root:root' | chroot root_${dist}_${mt}/ chpasswd");

printf("After-install customizations\n");
printf("Enable root login on ssh\n");
check_exec("sed -i  's/^PermitRootLogin .*$/PermitRootLogin yes/' root_${dist}_${mt}/etc/ssh/sshd_config");

printf("chroot done, umounting virtual filesystems\n");
check_exec("rm root_${dist}_${mt}/etc/policy-rc.d");
check_exec("rm root_${dist}_${mt}/usr/bin/qemu-arm-static");
do_unmount("root_${dist}_${mt}");

printf("creating virtual card image\n");
check_exec("truncate -s 2G ${dist}_${mt}.img");
check_exec("sfdisk ${dist}_${mt}.img < sources/card.layout");
$map=check_exec("kpartx -asv ${dist}_${mt}.img");
if(sizeof($map)!=2) {
  printf("Partition number does not equal 2\n");
  foreach($map as $line)
    printf("%s\n",trim($line));
}
list(,,$bootdisk)=explode(" ",$map[0]);
list(,,$sysdisk)=explode(" ",$map[1]);
$bootdisk="/dev/mapper/$bootdisk";
$sysdisk="/dev/mapper/$sysdisk";

printf("Formatting disk\n");
check_exec("mkfs.vfat -F 32 $bootdisk");
check_exec("mkfs.ext4 $sysdisk");
check_exec("mkdir p1_${mt}");
check_exec("mkdir p2_${mt}");
check_exec("mount $bootdisk p1_${mt}");
check_exec("mount $sysdisk p2_${mt}");

printf("Moving files to the loop partitions\n");
check_exec("mv root_${dist}_${mt}/boot/* p1_${mt}/");
check_exec("mv root_${dist}_${mt}/* p2_${mt}/");

printf("Unmounting the loop partitions\n");
check_exec("umount p1_${mt}");
check_exec("umount p2_${mt}");

printf("Unlooping image\n");
check_exec("kpartx -d ${dist}_${mt}.img");

printf("Compacting image\n");
check_exec("tar -czvf ${dist}_${mt}.tgz ${dist}_${mt}.img");

printf("Cleaning up\n");
check_exec("rm -rf root_${dist}_${mt}/ p1_${mt} p2_${mt} ${dist}_${mt}.img");
