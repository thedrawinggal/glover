_updated 3.17.24_

# set up a cultivate wordpress project

this guide will help you set up the cultivate wordpress stack for development.

## backend

you will need [MAMP](https://www.mamp.info/en/mac/) to set up and run the local php server

1. download [wordpress](https://wordpress.org/download/).
2. move all wordpress files and folders into the repository
3. point the MAMP server at the repository (preferences > server > choose...)
4. turn the MAMP server on, and create a new database in phpMyAdmin
5. navigate to `localhost:8888/wp-admin`, and run through the install
   1. use the database name you just created in phpMyAdmin
   2. use `root` for Username and Password
6. move the `cltv8-base-theme-v2` theme into `wp-content > themes` - you can delete any other themes in the folder
7. activate the theme in wordpress

## git and acf workflow

for new projects, keep git focused on the custom theme instead of the full wordpress install.

- track only the custom theme folder in the repository.
- use `production` as the deploy branch and `development` as the working branch.
- ignore local-only artifacts like `node_modules`, caches, logs, and machine-specific files.

this theme should also use ACF Local JSON from the start so field groups are versioned with the codebase.

- add an `acf-json/` folder in the theme and commit a `.gitkeep` if it is empty.
- add ACF save/load hooks in `functions.php` so ACF writes to and reads from `acf-json/`.
- when field groups change in wp-admin, save them there and commit the updated `acf-json/*.json` files with the related code changes.
- treat ACF field names as contracts. if a field name changes, update the related templates and logic in the same commit.

## frontend

the frontend uses [parcel](https://parceljs.org/) to bundle. It will serve an `app.css` file and an `app.js` file in `header.php` by default. parcel will hot reload on the save of any file in the `app.css` or `app.js` pipeline. you will need to manually refresh for `php` file changes, or integrate a solution like [browsersync](https://browsersync.io/).

### running the project

**theme tested at:**
`node 18.17.0`
`npm 10.5.0`

**install**
run `npm install` from the root of the theme directory. besides parcel, the only other package included by default is a custom gsap file, `gsap-bonus.tgz`, which adds gsap, as well as other [premium plugins we pay for](https://gsap.com/pricing/).

**available scripts:**
`npm run dev` is an alias for `parcel watch`
`npm run build` is an alias for `parcel build`

## troubleshooting

**parcel watch/build error on `npm run dev`**

```
parcel watch js/app.js styles/app.scss
Error: Got unexpected undefined
```

delete the `.parcel-cache` and `dist` directories, try `npm run dev` again

## upgrading from previous theme

the previous theme uses gulp for the frontend environment. gulp is no longer maintained, so if you are working on an old theme that uses gulp, it's good to upgrade it to this new theme when possible.

**new theme tested at:**
`node 18.17.0`
`npm 10.5.0`

- delete the `node_modules` and `dist` directory.
- migrate to the new `package.json`, transfer any dependencies and devDependencies you need from old project. it's likely you will be running a higher node version with the new theme, so consider whether to upgrade dependencies to higher versions.
- install the node packages `npm install`
- start the frontend `npm run dev`

**dealing with path, folder, file names:**

- the new `package.json` is configured for parcel to watch and build `src/js/app.js` and `src/styles/app.scss`. you can either bring your filenames in sync with this, or alter the scripts to look for different filenames in the `package.json`
- call local images directly from `src` directory, not eventual location `dist` directory
  - `<img src="<?php echo get_template_directory_uri(); ?>/images/logo.svg" />`
  - `background-image: url(../images/image.svg)`
